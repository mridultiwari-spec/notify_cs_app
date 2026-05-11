<?php
if (!function_exists('set_http_status')) {
    function set_http_status($code) {
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
            header('HTTP/1.1 ' . (int)$code);
        }
    }
}
require_once 'config/db.php';
require_once __DIR__ . '/app_config.php';
include 'accurate_country_code.php';
include 'send_sms_api.php';

$data = file_get_contents("php://input");
file_put_contents("webhook_log.txt", date('Y-m-d H:i:s') . "\n" . $data . "\n\n", FILE_APPEND);

$checkout = json_decode($data);

if (!$checkout) {
    set_http_status(200);
    exit;
}

$shop = isset($_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN']) ? $_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] : '';
if (!$shop) {
    file_put_contents("webhook_log.txt", "ERROR: Could not determine shop\n\n", FILE_APPEND);
    set_http_status(200);
    exit;
}

$pdo = getDatabaseConnection();
$prefix = $app_prefix;
$tables = $prefix . "shopify_sms_notification_app";
$stmt = $pdo->prepare("SELECT shop, session_access_token AS oauth_token FROM $tables WHERE shop = :shop");
$stmt->execute([':shop' => $shop]);
$shopRow = $stmt->fetch();

if (!$shopRow) {
    file_put_contents("webhook_log.txt", "ERROR: Shop not found\n\n", FILE_APPEND);
    set_http_status(200);
    exit;
}
$oauth_token = $shopRow['oauth_token'];

$checkout_id = isset($checkout->id) ? $checkout->id : '';
$checkout_url = isset($checkout->abandonedCheckoutUrl) ? $checkout->abandonedCheckoutUrl : '';
$created_at = isset($checkout->createdAt) ? $checkout->createdAt : date('Y-m-d H:i:s');
$updated_at = isset($checkout->updatedAt) ? $checkout->updatedAt : '';

$billing = isset($checkout->billingAddress) ? $checkout->billingAddress : null;
$billing_string = '';
if ($billing) {
    $billing_string = implode(', ', array_filter([
        isset($billing->name) ? $billing->name : '',
        isset($billing->address1) ? $billing->address1 : '',
        isset($billing->address2) ? $billing->address2 : '',
        isset($billing->city) ? $billing->city : '',
        isset($billing->countryCodeV2) ? $billing->countryCodeV2 : ''
    ]));
}

$shipping = isset($checkout->shippingAddress) ? $checkout->shippingAddress : null;
$shipping_string = '';
$shipping_city = '';
$shipping_state = '';
if ($shipping) {
    $shipping_string = implode(', ', array_filter([
        isset($shipping->name) ? $shipping->name : '',
        isset($shipping->address1) ? $shipping->address1 : '',
        isset($shipping->address2) ? $shipping->address2 : '',
        isset($shipping->city) ? $shipping->city : '',
        isset($shipping->province) ? $shipping->province : '',
        isset($shipping->zip) ? $shipping->zip : '',
        isset($shipping->countryCodeV2) ? $shipping->countryCodeV2 : ''
    ]));
    $shipping_city = isset($shipping->city) ? $shipping->city : '';
    $shipping_state = isset($shipping->province) ? $shipping->province : '';
}

$customer_name = isset($billing->name) ? $billing->name : (isset($shipping->name) ? $shipping->name : '');
$customer_name_parts = explode(' ', $customer_name, 2);
$customer_fname = isset($customer_name_parts[0]) ? $customer_name_parts[0] : '';
$customer_lname = isset($customer_name_parts[1]) ? $customer_name_parts[1] : '';
$customer_full_name = trim($customer_fname . ' ' . $customer_lname);
$customer_email = isset($checkout->email) ? $checkout->email : '';
$customer_phone = isset($billing->phone) ? $billing->phone : (isset($shipping->phone) ? $shipping->phone : '');

$country_code = '';
if ($billing && $billing->countryCodeV2) {
    $country_code = strtoupper($billing->countryCodeV2);
} elseif ($shipping && $shipping->countryCodeV2) {
    $country_code = strtoupper($shipping->countryCodeV2);
} else {
    $country_code = 'IN';
}

if ($country_code && $customer_phone) {
    $final_arr = getCountryCode_and_phone_number($country_code, $customer_phone);
    $country_code = $final_arr['country_code'];
    $customer_phone = $final_arr['phone_number'];
}

$total_price = '0.00';
if (isset($checkout->subtotalPriceSet->presentmentMoney->amount)) {
    $total_price = $checkout->subtotalPriceSet->presentmentMoney->amount;
}
$product_name = '';
$item_price = '';
$item_quantity = '';
$item_sku = '';
$item_vendor = '';
$total_weight = 0;
$total_discount = '';

$query = '
query GetAbandonedCheckout($id: ID!) {
    abandonedCheckout(id: $id) {
        id
        abandonedCheckoutUrl
        createdAt
        lineItems(first: 10) {
            edges {
                node {
                    title
                    quantity
                    originalTotalPrice {
                        amount
                    }
                    variant {
                        sku
                        weight
                        weightUnit
                        product {
                            vendor
                        }
                    }
                }
            }
        }
        discountApplications(first: 5) {
            edges {
                node {
                    totalAmount {
                        amount
                    }
                }
            }
        }
    }
}';

$checkout_gid = "gid://shopify/AbandonedCheckout/{$checkout_id}";
$variables = ['id' => $checkout_gid];

$url = "https://{$shop}/admin/api/2026-01/graphql.json";
$payload = json_encode([
    'query' => $query,
    'variables' => $variables
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "X-Shopify-Access-Token: {$oauth_token}"
    ],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

file_put_contents("webhook_log.txt", "GraphQL Response Code: $httpCode\n", FILE_APPEND);
file_put_contents("webhook_log.txt", "GraphQL Response: $response\n", FILE_APPEND);

$checkoutData = json_decode($response, true);

if (isset($checkoutData['data']['abandonedCheckout'])) {
    $abandoned = $checkoutData['data']['abandonedCheckout'];
    
    if (isset($abandoned['lineItems']['edges'])) {
        $products = [];
        foreach ($abandoned['lineItems']['edges'] as $edge) {
            $node = $edge['node'];
            $products[] = isset($node['title']) ? $node['title'] : '';
            $item_price = (isset($node['originalTotalPrice']) && isset($node['originalTotalPrice']['amount'])) ? $node['originalTotalPrice']['amount'] : '';
            $item_quantity = isset($node['quantity']) ? $node['quantity'] : '';
            $item_sku = (isset($node['variant']) && isset($node['variant']['sku'])) ? $node['variant']['sku'] : '';
            $item_vendor = (isset($node['variant']) && isset($node['variant']['product']) && isset($node['variant']['product']['vendor'])) ? $node['variant']['product']['vendor'] : '';
            $nodeWeight = (isset($node['variant']) && isset($node['variant']['weight'])) ? $node['variant']['weight'] : 0;
            $nodeQty = isset($node['quantity']) ? $node['quantity'] : 1;
            $total_weight += $nodeWeight * $nodeQty;
        }
        $product_name = implode(', ', $products);
    }
    if (isset($abandoned['discountApplications']['edges'])) {
        $discounts = [];
        foreach ($abandoned['discountApplications']['edges'] as $edge) {
            $discounts[] = (isset($edge['node']) && isset($edge['node']['totalAmount']) && isset($edge['node']['totalAmount']['amount'])) ? $edge['node']['totalAmount']['amount'] : 0;
        }
        $total_discount = array_sum($discounts);
    }
} else {
    file_put_contents("webhook_log.txt", "ERROR: Failed to fetch abandoned checkout details\n", FILE_APPEND);
}

$prefix = $app_prefix;
$table = $prefix . "shopify_sms_notification_App_Email_Notification";
$stmt = $pdo->prepare("SELECT * FROM $table WHERE aid = 5 AND shop = :shop AND sms_enabled = 1");
$stmt->execute([':shop' => $shop]);
$row = $stmt->fetch();

if ($row) {
    $sms_text = $row['sms'];
    $order_condition = $row['order_condition'];
    $condition_value = intval($row['price']);
    
    $created_time = new DateTime($created_at);
    $scheduled_time = clone $created_time;
    
    switch ($order_condition) {
        case 'hours':
            $scheduled_time->modify("+{$condition_value} hours");
            break;
        case 'days':
            $scheduled_time->modify("+" . ($condition_value * 24) . " hours");
            break;
        case 'weeks':
            $scheduled_time->modify("+" . ($condition_value * 7 * 24) . " hours");
            break;
        default:
            $scheduled_time->modify("+1 hours");
    }
    
    $scheduled_time_str = $scheduled_time->format('Y-m-d H:i:s');
    
    $searchVal = array(
        "{{ product_name }}",
        "{{ total_price }}",
        "{{ checkout_created_at }}",
        "{{ customer_fname }}",
        "{{ customer_lname }}",
        "{{ customer_email }}",
        "{{ customer_phone }}",
        "{{ country_code }}",
        "{{ checkout_url }}",
        "{{ Ad_order_number }}",
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
        $product_name,
        $total_price,
        $created_at,
        $customer_fname,
        $customer_lname,
        $customer_email,
        $customer_phone,
        $country_code,
        $checkout_url,
        $checkout_id,
        $total_discount,
        '', // Ad_order_status_url
        $item_price,
        $item_quantity,
        $item_sku,
        $item_vendor,
        $total_weight,
        $shipping_string,
        $billing_string
    );
    
    $final_sms = str_replace($searchVal, $replaceVal, $sms_text);
    $stmt = $pdo->prepare("
        INSERT INTO shopify_sms_notification_abandoned_log_details (
            shop, items_id, items_name, customer_email, country_code, phone_num, 
            scheduled_time, karix_req_details, status, created_at
        ) VALUES (
            :shop, :items_id, :items_name, :customer_email, :country_code, :phone_num,
            :scheduled_time, :karix_req_details, 'pending', NOW()
        )
    ");
    
    $stmt->execute([
        ':shop' => $shop,
        ':items_id' => $checkout_id,
        ':items_name' => $product_name,
        ':customer_email' => $customer_email,
        ':country_code' => $country_code,
        ':phone_num' => $customer_phone,
        ':scheduled_time' => $scheduled_time_str,
        ':karix_req_details' => $final_sms
    ]);
    
    file_put_contents("webhook_log.txt", "Abandoned checkout saved. Scheduled for: $scheduled_time_str\n", FILE_APPEND);
    file_put_contents("webhook_log.txt", "Final SMS: $final_sms\n", FILE_APPEND);
    
} else {
    file_put_contents("webhook_log.txt", "ERROR: No template found for aid=5 and shop=$shop\n", FILE_APPEND);
}

set_http_status(200);
?>