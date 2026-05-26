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

function fetchOrderDetailsFromShopify($shop, $oauth_token, $orderId)
{
    $query = '
    query GetOrder($id: ID!) {
        order(id: $id) {
            id
            name
            email
            phone
            customer {
                id
                firstName
                lastName
                defaultEmailAddress {
                    emailAddress
                }
                defaultPhoneNumber {
                    phoneNumber
                }
            }
            shippingAddress {
                phone
                address1
                address2
                city
                province
                zip
                countryCodeV2
                firstName
                lastName
            }
            billingAddress {
                phone
                address1
                address2
                city
                province
                zip
                countryCodeV2
                firstName
                lastName
            }
        }
    }';

    $variables = array(
        'id' => 'gid://shopify/Order/' . $orderId
    );

    $payload = array(
        'query' => $query,
        'variables' => $variables
    );

    $url = "https://{$shop}/admin/api/2024-01/graphql.json";
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json",
            "X-Shopify-Access-Token: {$oauth_token}"
        ),
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200) {
        $result = json_decode($response, true);
        if (isset($result['data']['order'])) {
            return $result['data']['order'];
        }
    }

    return null;
}

function fetchCustomerPhoneFromShopify($shop, $oauth_token, $customerId)
{
    $query = '{
        customer(id: "' . $customerId . '") {
            phone
            email
            firstName
            lastName
        }
    }';

    $url = "https://{$shop}/admin/api/2024-01/graphql.json";
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(array('query' => $query)),
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json",
            "X-Shopify-Access-Token: {$oauth_token}"
        ),
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200) {
        $result = json_decode($response, true);
        if (isset($result['data']['customer']['phone'])) {
            return $result['data']['customer']['phone'];
        }
    }

    return '';
}

// Step 1: Read raw payload
$data = file_get_contents("php://input");
//file_put_contents("$logFile", date('Y-m-d H:i:s') . "\n" . $data . "\n\n", FILE_APPEND);

$order_details = json_decode($data);

if (!$order_details) {
    set_http_status(200);
    exit;
}

$refund_id = isset($order_details->id) ? $order_details->id : '';
$order_id = isset($order_details->order_id) ? $order_details->order_id : '';
$email = isset($order_details->email) ? $order_details->email : '';

file_put_contents("$logFile", "Refund ID: $refund_id | Order ID: $order_id | Email: $email\n\n", FILE_APPEND);

if (!$order_id) {
    file_put_contents("$logFile", "ERROR: Could not determine order_id\n\n", FILE_APPEND);
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

// Fetch order details from Shopify using GraphQL
$orderData = fetchOrderDetailsFromShopify($shop, $oauth_token, $order_id);

$transaction_type = '';
if (isset($order_details->transactions) && is_array($order_details->transactions) && !empty($order_details->transactions)) {
    $transaction_type = isset($order_details->transactions[0]->kind) ? $order_details->transactions[0]->kind : '';
}

$total_discount = '';
$total_weight = 0;
$order_name = '';

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

// Fix 12: customer not in refund payload root
// Fix 12: customer not in refund payload root
$customer = isset($order_details->customer) ? $order_details->customer : null;

// Initialize customer name variables
$customer_fname = '';
$customer_lname = '';

// Source 1: Try to get from customer object in webhook
if ($customer && isset($customer->first_name)) {
    $customer_fname = $customer->first_name;
    $customer_lname = isset($customer->last_name) ? $customer->last_name : '';
    file_put_contents("$logFile", "INFO: Got customer name from webhook customer: {$customer_fname} {$customer_lname}\n", FILE_APPEND);
}
// Source 2: Try to get from billing address
elseif ($billing && isset($billing->first_name)) {
    $customer_fname = $billing->first_name;
    $customer_lname = isset($billing->last_name) ? $billing->last_name : '';
    file_put_contents("$logFile", "INFO: Got customer name from billing address: {$customer_fname} {$customer_lname}\n", FILE_APPEND);
}
// Source 3: Try to get from shipping address
elseif ($shipping && isset($shipping->first_name)) {
    $customer_fname = $shipping->first_name;
    $customer_lname = isset($shipping->last_name) ? $shipping->last_name : '';
    file_put_contents("$logFile", "INFO: Got customer name from shipping address: {$customer_fname} {$customer_lname}\n", FILE_APPEND);
}

$customer_full_name = trim($customer_fname . ' ' . $customer_lname);
$customer_email_id = isset($order_details->contact_email) ? $order_details->contact_email : '';

// Get customer ID for GraphQL lookup
$customer_id = '';
if ($customer && isset($customer->id)) {
    $customer_id = $customer->id;
} elseif (isset($order_details->customer_id)) {
    $customer_id = $order_details->customer_id;
}

$customer_shipping_phone = ($shipping && isset($shipping->phone)) ? $shipping->phone : '';
$customer_billing_phone = ($billing && isset($billing->phone)) ? $billing->phone : '';
$customer_root_phone = isset($order_details->phone) ? $order_details->phone : '';
$shipping_country_code = ($shipping && isset($shipping->country_code)) ? $shipping->country_code : '';
$billing_country_code = ($billing && isset($billing->country_code)) ? $billing->country_code : '';

// Get phone number from fetched order data (root, shipping, billing)
$order_root_phone = '';
$order_shipping_phone = '';
$order_billing_phone = '';
$order_customer_phone = '';

if ($orderData) {
    // Get order root phone
    $order_root_phone = isset($orderData['phone']) ? $orderData['phone'] : '';

    // Get shipping address phone
    if (isset($orderData['shippingAddress']) && isset($orderData['shippingAddress']['phone'])) {
        $order_shipping_phone = $orderData['shippingAddress']['phone'];
    }

    // Get billing address phone
    if (isset($orderData['billingAddress']) && isset($orderData['billingAddress']['phone'])) {
        $order_billing_phone = $orderData['billingAddress']['phone'];
    }

    // Get customer default phone number
    if (isset($orderData['customer']['defaultPhoneNumber']) && isset($orderData['customer']['defaultPhoneNumber']['phoneNumber'])) {
        $order_customer_phone = $orderData['customer']['defaultPhoneNumber']['phoneNumber'];
    }

        // Update customer email from order data if available
    if (isset($orderData['customer']['defaultEmailAddress']) && isset($orderData['customer']['defaultEmailAddress']['emailAddress'])) {
        $customer_email_id = $orderData['customer']['defaultEmailAddress']['emailAddress'];
    } elseif (isset($orderData['email'])) {
        $customer_email_id = $orderData['email'];
    }

    // Update customer name from order data if available
    if (isset($orderData['customer']['firstName']) && isset($orderData['customer']['lastName'])) {
        $customer_fname = $orderData['customer']['firstName'];
        $customer_lname = $orderData['customer']['lastName'];
        $customer_full_name = trim($customer_fname . ' ' . $customer_lname);
        file_put_contents("$logFile", "INFO: Updated customer name from GraphQL order customer: {$customer_fname} {$customer_lname}\n", FILE_APPEND);
    }
    // Fallback to shipping address from order data
    elseif (isset($orderData['shippingAddress']['firstName'])) {
        $customer_fname = $orderData['shippingAddress']['firstName'];
        $customer_lname = isset($orderData['shippingAddress']['lastName']) ? $orderData['shippingAddress']['lastName'] : '';
        $customer_full_name = trim($customer_fname . ' ' . $customer_lname);
        file_put_contents("$logFile", "INFO: Updated customer name from GraphQL order shipping address: {$customer_fname} {$customer_lname}\n", FILE_APPEND);
    }
    // Fallback to billing address from order data
    elseif (isset($orderData['billingAddress']['firstName'])) {
        $customer_fname = $orderData['billingAddress']['firstName'];
        $customer_lname = isset($orderData['billingAddress']['lastName']) ? $orderData['billingAddress']['lastName'] : '';
        $customer_full_name = trim($customer_fname . ' ' . $customer_lname);
        file_put_contents("$logFile", "INFO: Updated customer name from GraphQL order billing address: {$customer_fname} {$customer_lname}\n", FILE_APPEND);
    }

    // Update order name
    if (isset($orderData['name'])) {
        $order_name = $orderData['name'];
    }
}

if ($shipping_country_code) {
    $country_code = strtoupper($shipping_country_code);
} elseif ($billing_country_code) {
    $country_code = strtoupper($billing_country_code);
} else {
    $country_code = 'IN';
}

$customer_details_phone = ($customer && isset($customer->phone)) ? $customer->phone : '';
$customer_order_phone = isset($order_details->phone) ? $order_details->phone : '';

// Check if we have phone from order data first
if (!empty($order_root_phone)) {
    $customer_details_phone = $order_root_phone;
    file_put_contents("$logFile", "INFO: Found phone in order root: {$order_root_phone}\n", FILE_APPEND);
} elseif (!empty($order_shipping_phone)) {
    $customer_details_phone = $order_shipping_phone;
    file_put_contents("$logFile", "INFO: Found phone in order shipping address: {$order_shipping_phone}\n", FILE_APPEND);
} elseif (!empty($order_billing_phone)) {
    $customer_details_phone = $order_billing_phone;
    file_put_contents("$logFile", "INFO: Found phone in order billing address: {$order_billing_phone}\n", FILE_APPEND);
} elseif (!empty($order_customer_phone)) {
    $customer_details_phone = $order_customer_phone;
    file_put_contents("$logFile", "INFO: Found phone in customer defaultPhoneNumber: {$order_customer_phone}\n", FILE_APPEND);
} elseif (
    empty($customer_shipping_phone) && empty($customer_billing_phone) &&
    empty($customer_root_phone) && empty($customer_details_phone) && !empty($customer_id)
) {

    $graphql_phone = fetchCustomerPhoneFromShopify($shop, $oauth_token, $customer_id);
    if (!empty($graphql_phone)) {
        $customer_details_phone = $graphql_phone;
        file_put_contents("$logFile", "INFO: Fetched phone from GraphQL for customer {$customer_id}: {$graphql_phone}\n", FILE_APPEND);
    } else {
        file_put_contents("$logFile", "WARNING: Could not fetch phone from GraphQL for customer {$customer_id}\n", FILE_APPEND);
    }
}

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

file_put_contents("$logFile", "DEBUG: Phone sources - shipping: {$customer_shipping_phone}, billing: {$customer_billing_phone}, customer: {$customer_details_phone}, root: {$customer_root_phone}, order_root: {$order_root_phone}, order_shipping: {$order_shipping_phone}, order_billing: {$order_billing_phone}, final: {$customer_phone}\n", FILE_APPEND);

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
$refund_subtotal_arr = array();
if (isset($order_details->refund_line_items) && is_array($order_details->refund_line_items)) {
    foreach ($order_details->refund_line_items as $refund_line_item) {
        $line_item = isset($refund_line_item->line_item) ? $refund_line_item->line_item : null;
        if ($line_item) {
            $item_name_arr[] = isset($line_item->name) ? $line_item->name : '';
            $item_price_arr[] = isset($line_item->price) ? $line_item->price : '';
            $item_quantity_arr[] = isset($refund_line_item->quantity) ? $refund_line_item->quantity : '';
            $item_sku_arr[] = isset($line_item->sku) ? $line_item->sku : '';
            $item_vendor_arr[] = isset($line_item->vendor) ? $line_item->vendor : '';
            $refund_subtotal_arr[] = isset($refund_line_item->subtotal) ? $refund_line_item->subtotal : '';
        }
    }
}
$item_name = implode(', ', $item_name_arr);
$item_price = isset($item_price_arr[0]) ? $item_price_arr[0] : '';
$item_quantity = isset($item_quantity_arr[0]) ? $item_quantity_arr[0] : '';
$item_sku = isset($item_sku_arr[0]) ? $item_sku_arr[0] : '';
$item_vendor = isset($item_vendor_arr[0]) ? $item_vendor_arr[0] : '';
$refund_subtotal = isset($refund_subtotal_arr[0]) ? $refund_subtotal_arr[0] : '';
date_default_timezone_set("Asia/Kolkata");
$current_DateTime = date('Y-m-d H:i:s');
$finalDateTime = $current_DateTime;

$subject = ucwords("Order Refund");

$prefix = $app_prefix;
$table = $prefix . "shopify_sms_notification_App_Email_Notification";
$stmt = $pdo->prepare("SELECT * FROM $table WHERE aid = 4 AND shop = :shop");
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
        $sms_variables = array();
        $has_parameters = false;

        if (isset($row['sms_variables']) && !empty($row['sms_variables'])) {
            $sms_variables = json_decode($row['sms_variables'], true);
            if (!is_array($sms_variables)) {
                $sms_variables = array();
            }
            if (count($sms_variables) > 0) {
                $has_parameters = true;
                file_put_contents("$logFile", "SMS Variables loaded (has parameters): " . print_r($sms_variables, true) . "\n", FILE_APPEND);
            } else {
                file_put_contents("$logFile", "SMS Variables is empty array\n", FILE_APPEND);
            }
        } else {
            file_put_contents("$logFile", "SMS Variables is NULL or empty\n", FILE_APPEND);
        }
        $replacementMap = array(
            "{{ order_name }}" => $order_name,
            "{{ Ad_order_number }}" => $order_id,
            "{{ transaction_type }}" => $transaction_type,
            "{{ customer_fname }}" => $customer_fname,
            "{{ customer_lname }}" => $customer_lname,
            "{{ customer_email_id }}" => $customer_email_id,
            "{{ country_code }}" => $country_code,
            "{{ customer_phone }}" => $customer_phone,
            "{{ item_name }}" => $item_name,
            "{{ customer_full_name }}" => $customer_full_name,
            "{{ Ad_total_discount }}" => $total_discount,
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

            file_put_contents("$logFile", "========== REFUND SMS ==========\n", FILE_APPEND);
            file_put_contents("$logFile", "Original SMS Template: $sms_text\n", FILE_APPEND);
            file_put_contents("$logFile", "Processed SMS Text: $processed_sms_text\n", FILE_APPEND);
            file_put_contents("$logFile", "Phone: $customer_phone, Country: $country_code\n", FILE_APPEND);
            file_put_contents("$logFile", "Transaction Type: $transaction_type\n", FILE_APPEND);
            file_put_contents("$logFile", "==============================\n\n", FILE_APPEND);

            $parameter_values = array();
            foreach ($sms_variables as $key => $value) {
                $placeholder = "{{ " . trim($key) . " }}";
                if (isset($replacementMap[$placeholder])) {
                    $parameter_values[$key] = $replacementMap[$placeholder];
                } else {
                    $parameter_values[$key] = $value;
                }
            }
            $template_id = $template_name_sms;

            file_put_contents("$logFile", "Parameter Values for template: " . json_encode($parameter_values) . "\n", FILE_APPEND);
            file_put_contents("$logFile", "Template ID: " . $template_id . "\n", FILE_APPEND);


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
                if (isset($order_details->refund_line_items) && !empty($order_details->refund_line_items)) {
                    $first_line_item = isset($order_details->refund_line_items[0]->line_item) ? $order_details->refund_line_items[0]->line_item : null;
                    if ($first_line_item && isset($first_line_item->product_id)) {
                        $product_id = $first_line_item->product_id;
                    }
                }
                if ($product_id) {
                    $aid = 4;
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
                file_put_contents("$logFile", "ERROR: Customer phone is empty, cannot send SMS for refund {$refund_id}\n", FILE_APPEND);
            } elseif (empty($template_id)) {
                file_put_contents("$logFile", "ERROR: Template ID is empty, cannot send template SMS for refund {$refund_id}\n", FILE_APPEND);
            } else {
                if (function_exists('send_smstext_with_parameters')) {
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
                        $subject
                    );
                    file_put_contents("$logFile", "Template SMS sent successfully for refund {$refund_id} to {$customer_phone}\n", FILE_APPEND);
                    file_put_contents("$logFile", "SMS Text with replacements: {$processed_sms_text}\n", FILE_APPEND);
                } else {
                    send_smstext($country_code, $customer_phone, $template_name_sms, $shop, $customer_email_id, $customer_full_name, $order_id, $order_name, "Order Refund");
                    file_put_contents("$logFile", "Template SMS (fallback) sent successfully for refund {$refund_id} to {$customer_phone}\n", FILE_APPEND);
                }
            }

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
                "{{ transaction_type }}",
                "{{ customer_fname }}",
                "{{ customer_lname }}",
                "{{ customer_email_id }}",
                "{{ country_code }}",
                "{{ customer_phone }}",
                "{{ item_name }}",
                "{{ customer_full_name }}",
                "{{ Ad_total_discount }}",
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
                $transaction_type,
                $customer_fname,
                $customer_lname,
                $customer_email_id,
                $country_code,
                $customer_phone,
                $item_name,
                $customer_full_name,
                $total_discount,
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

            // CHANGE: Added fallback for empty SMS text
            if (empty($final_text) || empty($sms_text)) {
                $final_text = "A refund has been processed for your order {$order_id}. Transaction type: {$transaction_type}";
                file_put_contents("$logFile", "WARNING: Using fallback SMS text for refund\n", FILE_APPEND);
            }

            $searchReplaceMap = array(
                '{{ order_name }}' => isset($order_name) ? $order_name : null,
                '{{ Ad_order_number }}' => isset($order_id) ? $order_id : null,
                '{{ transaction_type }}' => isset($transaction_type) ? $transaction_type : null,
                '{{ customer_fname }}' => isset($customer_fname) ? $customer_fname : null,
                '{{ customer_lname }}' => isset($customer_lname) ? $customer_lname : null,
                '{{ customer_email_id }}' => isset($customer_email_id) ? $customer_email_id : null,
                '{{ country_code }}' => isset($country_code) ? $country_code : null,
                '{{ customer_phone }}' => isset($customer_phone) ? $customer_phone : null,
                '{{ item_name }}' => isset($item_name) ? $item_name : null,
                '{{ customer_full_name }}' => isset($customer_full_name) ? $customer_full_name : null,
                '{{ Ad_total_discount }}' => isset($total_discount) ? $total_discount : null,
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

            if ($media_source_prod == 'dynamic' && $media_type == 'image') {
                $product_id = null;
                if (isset($order_details->refund_line_items) && !empty($order_details->refund_line_items)) {
                    $first_line_item = isset($order_details->refund_line_items[0]->line_item) ? $order_details->refund_line_items[0]->line_item : null;
                    if ($first_line_item && isset($first_line_item->product_id)) {
                        $product_id = $first_line_item->product_id;
                    }
                }
                if ($product_id) {
                    $aid = 4;
                    $result = fetchProductImageAndUpdateDb($pdo, $shop, $oauth_token, $product_id, $aid, $whatsapp_media_source, $prefix);
                    if ($result['success']) {
                        $media_url = $result['media_url'];
                    } else {
                        error_log("Image fetch error: " . $result['message']);
                    }
                } else {
                    error_log("No product ID found for shop: {$shop}");
                }
            }

            // CHANGE: Added validation before sending SMS with correct parameter order
            if (empty($customer_phone)) {
                file_put_contents("$logFile", "ERROR: Customer phone is empty, cannot send SMS for refund {$refund_id}\n", FILE_APPEND);
            } elseif (empty($final_text)) {
                file_put_contents("$logFile", "ERROR: SMS text is empty, cannot send SMS for refund {$refund_id}\n", FILE_APPEND);
            } else {
                send_smstext($country_code, $customer_phone, $template_name_sms, $shop, $customer_email_id, $customer_full_name, $order_id, $order_name, "Order Refund");
            }
        }
    }
} else {

    file_put_contents("$logFile", "WARNING: No enabled SMS template found for aid=4 and shop={$shop}\n", FILE_APPEND);
}

file_put_contents(
    "$logFile",
    "Transaction Type: $transaction_type\nDiscount: $total_discount\nBilling: $billing_string\nShipping: $shipping_string\n\n",
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
// Only proceed if a WhatsApp template is configured
if ($whatsapp_enabled == 1 && !empty($whatsapp_template_name)) {
    file_put_contents("$logFile", "Processing WhatsApp for refund created - Template: $whatsapp_template_name\n", FILE_APPEND);

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
        'transaction_type' => $transaction_type,
        'customer_fname' => $customer_fname,
        'customer_lname' => $customer_lname,
        'customer_email_id' => $customer_email_id,
        'country_code' => $country_code,
        'customer_phone' => $customer_phone,
        'item_name' => $item_name,
        'customer_full_name' => $customer_full_name,
        'Ad_total_discount' => $total_discount,
        'Ad_item_price' => $item_price,
        'Ad_item_quantity' => $item_quantity,
        'Ad_item_sku' => $item_sku,
        'Ad_item_vendor' => $item_vendor,
        'Ad_total_weight' => $total_weight,
        'Ad_shipping_address' => $shipping_string,
        'Ad_billing_address' => $billing_string,
        'order_id' => $order_id,
        'refund_id' => $refund_id
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
        if (isset($order_details->refund_line_items) && !empty($order_details->refund_line_items)) {
            $first_line_item = isset($order_details->refund_line_items[0]->line_item) ? $order_details->refund_line_items[0]->line_item : null;
            if ($first_line_item && isset($first_line_item->product_id)) {
                $product_id = $first_line_item->product_id;
            }
        }
        if ($product_id) {
            $aid = 4;
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
        'notification_type' => 'Order Refund'
    ));
    if (empty($customer_phone)) {
        file_put_contents("$logFile", "ERROR: Customer phone is empty, cannot send WhatsApp for refund {$refund_id}\n", FILE_APPEND);
    } else {
        $whatsapp_result = send_whatsapp_message($whatsapp_config, $pdo, $table2);

        if ($whatsapp_result['success']) {
            file_put_contents("$logFile", "WhatsApp sent successfully for refund {$refund_id} to {$customer_phone}\n", FILE_APPEND);
        } else {
            file_put_contents("$logFile", "WhatsApp failed for refund {$refund_id}: " . $whatsapp_result['message'] . "\n", FILE_APPEND);
        }
    }
} else {
    file_put_contents("$logFile", "No WhatsApp template configured for aid=4, skipping WhatsApp send\n", FILE_APPEND);
}
?>