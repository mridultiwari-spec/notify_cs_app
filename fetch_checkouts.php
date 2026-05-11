<?php
require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/config/db.php';

function fetchAndStoreAbandonedCheckouts($shop, $oauth_token)
{
    global $pdo, $prefix;
    $pdo =  getDatabaseConnection();
    $table = $prefix . "abandoned_checkouts";

    createAbandonedCheckoutsTable($pdo, $table);
    $has_next_page = true;
    $cursor = null;

    $total_fetched = 0;
    $total_inserted = 0;
    $total_updated = 0;

    $api_url = "https://" . $shop . "/admin/api/2026-01/graphql.json";

    while ($has_next_page) {

        $after_query = '';

        if ($cursor !== null) {
            $after_query = ', after: "' . $cursor . '"';
        }
        $query = '
        query {
  abandonedCheckouts(first: 250' . $after_query . ') {
    pageInfo {
        hasNextPage
    }
    edges {
      cursor
      node {
        id
        abandonedCheckoutUrl
        createdAt
        completedAt
        name
        note
        totalPriceSet {
          shopMoney {
            amount
            currencyCode
          }
        }
        subtotalPriceSet {
          shopMoney {
            amount
            currencyCode
          }
        }
        totalTaxSet {
          shopMoney {
            amount
            currencyCode
          }
        }
        lineItems(first: 250) {
          edges {
            node {
              id
              sku
              title
              quantity
              variant {
                id
                title
                sku
                price
              }
            }
          }
        }
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
          address1
          address2
          city
          company
          country
          countryCodeV2
          firstName
          lastName
          phone
          province
          zip
        }
        billingAddress {
          address1
          address2
          city
          company
          country
          countryCodeV2
          firstName
          lastName
          phone
          province
          zip
        }
      }
    }
  }
}
';
        $response = shopifyGraphQLRequest($api_url, $oauth_token, $query);

        if (!$response || isset($response['errors'])) {
            $error_msg = isset($response['errors'])
                ? json_encode($response['errors'])
                : 'API request failed';

            file_put_contents(
                "/file/debug_log.txt",
                //date('Y-m-d H:i:s') . " ERROR: " . $error_msg . "\n",
                FILE_APPEND
            );
            break;
        }

        $checkouts = array();

        if (isset($response['data']['abandonedCheckouts']['edges'])) {
            $checkouts = $response['data']['abandonedCheckouts']['edges'];
        }

        if (empty($checkouts)) {
            $has_next_page = false;
            break;
        }

        foreach ($checkouts as $edge) {

            if (!isset($edge['node'])) {
                continue;
            }

            $checkout = $edge['node'];

            $total_fetched++;

            $checkout_id = '';

            if (isset($checkout['id'])) {
                $checkout_id = extractShopifyId($checkout['id']);
            }

            if (empty($checkout_id)) {
                continue;
            }

            if (!empty($checkout['completedAt'])) {
                continue;
            }

            $customer_email = '';
            $customer_phone = '';
            $customer_first_name = '';
            $customer_last_name = '';

            if (isset($checkout['customer'])) {

                $customer_email = isset($checkout['customer']['defaultEmailAddress']['emailAddress'])
                    ? $checkout['customer']['defaultEmailAddress']['emailAddress']
                    : '';

                $customer_phone = isset($checkout['customer']['defaultPhoneNumber']['phoneNumber'])
                    ? $checkout['customer']['defaultPhoneNumber']['phoneNumber']
                    : '';

                $customer_first_name = isset($checkout['customer']['firstName'])
                    ? $checkout['customer']['firstName']
                    : '';

                $customer_last_name = isset($checkout['customer']['lastName'])
                    ? $checkout['customer']['lastName']
                    : '';
            }
            if (
                empty($customer_first_name)
                && isset($checkout['shippingAddress']['firstName'])
            ) {
                $customer_first_name = $checkout['shippingAddress']['firstName'];
            }

            if (
                empty($customer_last_name)
                && isset($checkout['shippingAddress']['lastName'])
            ) {
                $customer_last_name = $checkout['shippingAddress']['lastName'];
            }
            if (
                empty($customer_phone)
                && isset($checkout['shippingAddress']['phone'])
            ) {
                $customer_phone = $checkout['shippingAddress']['phone'];
            }

            if (
                empty($customer_phone)
                && isset($checkout['billingAddress']['phone'])
            ) {
                $customer_phone = $checkout['billingAddress']['phone'];
            }

            $line_items = array();

            if (isset($checkout['lineItems']['edges'])) {

                foreach ($checkout['lineItems']['edges'] as $item_edge) {

                    $item = $item_edge['node'];

                    $line_items[] = array(
                        'id' => isset($item['id'])
                            ? extractShopifyId($item['id'])
                            : '',

                        'sku' => isset($item['sku'])
                            ? $item['sku']
                            : '',

                        'title' => isset($item['title'])
                            ? $item['title']
                            : '',

                        'quantity' => isset($item['quantity'])
                            ? $item['quantity']
                            : 0,

                        'variant_id' => isset($item['variant']['id'])
                            ? extractShopifyId($item['variant']['id'])
                            : '',

                        'variant_title' => isset($item['variant']['title'])
                            ? $item['variant']['title']
                            : '',

                        'variant_sku' => isset($item['variant']['sku'])
                            ? $item['variant']['sku']
                            : '',

                        'variant_price' => isset($item['variant']['price'])
                            ? $item['variant']['price']
                            : ''
                    );
                }
            }
            $checkout_data = array(
                'checkout_url' => isset($checkout['abandonedCheckoutUrl'])
                    ? $checkout['abandonedCheckoutUrl']
                    : '',

                'checkout_name' => isset($checkout['name'])
                    ? $checkout['name']
                    : '',

                'customer_email' => $customer_email,
                'customer_phone' => $customer_phone,

                'customer_first_name' => $customer_first_name,
                'customer_last_name' => $customer_last_name,

                'total_price' => isset($checkout['totalPriceSet']['shopMoney']['amount'])
                    ? $checkout['totalPriceSet']['shopMoney']['amount']
                    : '0',

                'subtotal_price' => isset($checkout['subtotalPriceSet']['shopMoney']['amount'])
                    ? $checkout['subtotalPriceSet']['shopMoney']['amount']
                    : '0',

                'total_tax' => isset($checkout['totalTaxSet']['shopMoney']['amount'])
                    ? $checkout['totalTaxSet']['shopMoney']['amount']
                    : '0',

                'currency' => isset($checkout['totalPriceSet']['shopMoney']['currencyCode'])
                    ? $checkout['totalPriceSet']['shopMoney']['currencyCode']
                    : '',

                'shipping_address' => json_encode(
                    isset($checkout['shippingAddress'])
                    ? $checkout['shippingAddress']
                    : array()
                ),

                'billing_address' => json_encode(
                    isset($checkout['billingAddress'])
                    ? $checkout['billingAddress']
                    : array()
                ),

                'line_items' => json_encode($line_items),

                'raw_data' => json_encode($checkout),

                'created_at' => isset($checkout['createdAt'])
            );

            $result = storeAbandonedCheckout(
                $pdo,
                $table,
                $shop,
                $checkout_id,
                $checkout_data
            );

            if ($result == 'inserted') {
                $total_inserted++;
            }

            if ($result == 'updated') {
                $total_updated++;
            }
        }

        // if (count($checkouts) < 250) {

        //     $has_next_page = false;

        // } else {

        //     $last_edge = end($checkouts);

        //     $cursor = isset($last_edge['cursor'])
        //         ? $last_edge['cursor']
        //         : null;
        // }
        $page_info = isset($response['data']['abandonedCheckouts']['pageInfo'])
            ? $response['data']['abandonedCheckouts']['pageInfo']
            : array();

        $has_next_page = isset($page_info['hasNextPage'])
            ? $page_info['hasNextPage']
            : false;

        if ($has_next_page) {

            $last_edge = end($checkouts);

            $cursor = isset($last_edge['cursor'])
                ? $last_edge['cursor']
                : null;

        } else {

            $cursor = null;
        }

        if ($total_fetched >= 2000) {

            file_put_contents(
                "/file/debug_log.txt",
                //date('Y-m-d H:i:s') . " WARNING: 2000 checkout limit reached\n",
                FILE_APPEND
            );

            break;
        }
    }

    $deleted_count = deleteProcessedAbandonedCheckouts($pdo, $table);

    file_put_contents(
        "/file/debug_log.txt",
        //date('Y-m-d H:i:s') .
        " COMPLETED | Shop: " . $shop .
        " | Fetched: " . $total_fetched .
        " | Inserted: " . $total_inserted .
        " | Updated: " . $total_updated .
        " | Deleted Processed: " . $deleted_count . "\n",
        FILE_APPEND
    );

    return array(
        'total_fetched' => $total_fetched,
        'total_inserted' => $total_inserted,
        'total_updated' => $total_updated,
        'deleted_processed' => $deleted_count
    );
}

function createAbandonedCheckoutsTable($pdo, $table)
{
    $sql = "
    CREATE TABLE IF NOT EXISTS `$table` (

        `id` INT(11) NOT NULL AUTO_INCREMENT,

        `checkout_id` VARCHAR(255) NOT NULL,
        `shop` VARCHAR(255) NOT NULL,

        `checkout_url` TEXT,
        `checkout_name` VARCHAR(255) DEFAULT NULL,

        `customer_email` VARCHAR(255) DEFAULT NULL,
        `customer_phone` VARCHAR(50) DEFAULT NULL,

        `customer_first_name` VARCHAR(255) DEFAULT NULL,
        `customer_last_name` VARCHAR(255) DEFAULT NULL,

        `total_price` DECIMAL(10,2) DEFAULT 0.00,
        `subtotal_price` DECIMAL(10,2) DEFAULT 0.00,
        `total_tax` DECIMAL(10,2) DEFAULT 0.00,

        `currency` VARCHAR(10) DEFAULT NULL,

        `shipping_address` TEXT,
        `billing_address` TEXT,

        `line_items` LONGTEXT,
        `raw_data` LONGTEXT,

        `status` TINYINT(1) DEFAULT 0 COMMENT '0=new,1=processed',

        `processed_at` DATETIME DEFAULT NULL,

        `created_at` DATETIME DEFAULT NULL,
        `updated_at` DATETIME DEFAULT NULL,

        PRIMARY KEY (`id`),

        UNIQUE KEY `uk_checkout_shop` (`checkout_id`,`shop`),

        KEY `idx_shop_status` (`shop`,`status`),
        KEY `idx_created_at` (`created_at`)

    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    try {
        $pdo->exec($sql);
        return true;
    } catch (PDOException $e) {

        file_put_contents(
            "/file/debug_log.txt",
            //date('Y-m-d H:i:s') .
            " ERROR creating table: " .
            $e->getMessage() . "\n",
            FILE_APPEND
        );

        return false;
    }
}

function storeAbandonedCheckout($pdo, $table, $shop, $checkout_id, $data)
{
    try {
        $check_sql = "
        SELECT id
        FROM `$table`
        WHERE checkout_id = :checkout_id
        AND shop = :shop
        LIMIT 1
        ";

        $check_stmt = $pdo->prepare($check_sql);

        $check_stmt->execute(array(
            ':checkout_id' => $checkout_id,
            ':shop' => $shop
        ));

        $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $sql = "
            UPDATE `$table`
            SET
                checkout_url = :checkout_url,
                checkout_name = :checkout_name,

                customer_email = :customer_email,
                customer_phone = :customer_phone,

                customer_first_name = :customer_first_name,
                customer_last_name = :customer_last_name,

                total_price = :total_price,
                subtotal_price = :subtotal_price,
                total_tax = :total_tax,

                currency = :currency,

                shipping_address = :shipping_address,
                billing_address = :billing_address,

                line_items = :line_items,
                raw_data = :raw_data,

                updated_at = NOW()

            WHERE id = :id
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->execute(array(

                ':checkout_url' => $data['checkout_url'],
                ':checkout_name' => $data['checkout_name'],

                ':customer_email' => $data['customer_email'],
                ':customer_phone' => $data['customer_phone'],

                ':customer_first_name' => $data['customer_first_name'],
                ':customer_last_name' => $data['customer_last_name'],

                ':total_price' => $data['total_price'],
                ':subtotal_price' => $data['subtotal_price'],
                ':total_tax' => $data['total_tax'],

                ':currency' => $data['currency'],

                ':shipping_address' => $data['shipping_address'],
                ':billing_address' => $data['billing_address'],

                ':line_items' => $data['line_items'],
                ':raw_data' => $data['raw_data'],

                ':id' => $existing['id']
            ));

            return 'updated';
        }
        $sql = "
        INSERT INTO `$table` (

            checkout_id,
            shop,

            checkout_url,
            checkout_name,

            customer_email,
            customer_phone,

            customer_first_name,
            customer_last_name,

            total_price,
            subtotal_price,
            total_tax,

            currency,

            shipping_address,
            billing_address,

            line_items,
            raw_data,

            status,
            created_at,
            updated_at

        ) VALUES (

            :checkout_id,
            :shop,

            :checkout_url,
            :checkout_name,

            :customer_email,
            :customer_phone,

            :customer_first_name,
            :customer_last_name,

            :total_price,
            :subtotal_price,
            :total_tax,

            :currency,

            :shipping_address,
            :billing_address,

            :line_items,
            :raw_data,

            0,
            :created_at,
            NOW()
        )
        ";

        $stmt = $pdo->prepare($sql);

        $stmt->execute(array(

            ':checkout_id' => $checkout_id,
            ':shop' => $shop,

            ':checkout_url' => $data['checkout_url'],
            ':checkout_name' => $data['checkout_name'],

            ':customer_email' => $data['customer_email'],
            ':customer_phone' => $data['customer_phone'],

            ':customer_first_name' => $data['customer_first_name'],
            ':customer_last_name' => $data['customer_last_name'],

            ':total_price' => $data['total_price'],
            ':subtotal_price' => $data['subtotal_price'],
            ':total_tax' => $data['total_tax'],

            ':currency' => $data['currency'],

            ':shipping_address' => $data['shipping_address'],
            ':billing_address' => $data['billing_address'],

            ':line_items' => $data['line_items'],
            ':raw_data' => $data['raw_data'],

            ':created_at' => $data['created_at']
        ));

        return 'inserted';

    } catch (PDOException $e) {

        file_put_contents(
            "/file/debug_log.txt",
            //date('Y-m-d H:i:s') .
            " ERROR storing checkout " .
            $checkout_id .
            ": " .
            $e->getMessage() . "\n",
            FILE_APPEND
        );

        return false;
    }
}
function deleteProcessedAbandonedCheckouts($pdo, $table)
{
    try {

        $sql = "
        DELETE FROM `$table`
        WHERE status = 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount();

    } catch (PDOException $e) {

        file_put_contents(
            "/file/debug_log.txt",
            //date('Y-m-d H:i:s') .
            " ERROR deleting processed rows: " .
            $e->getMessage() . "\n",
            FILE_APPEND
        );

        return 0;
    }
}
function shopifyGraphQLRequest($url, $access_token, $query)
{
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_POST, true);

    curl_setopt(
        $ch,
        CURLOPT_POSTFIELDS,
        json_encode(array(
            'query' => $query
        ))
    );

    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'X-Shopify-Access-Token: ' . $access_token
    ));

    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $error = curl_error($ch);

    curl_close($ch);

    if ($error) {

        file_put_contents(
            "/file/debug_log.txt",
            ////date('Y-m-d H:i:s') .
            " CURL ERROR: " .
            $error . "\n",
            FILE_APPEND
        );

        return false;
    }

    if ($http_code != 200) {

        file_put_contents(
            "/file/debug_log.txt",
            //date('Y-m-d H:i:s') .
            " HTTP ERROR: " .
            $http_code .
            " RESPONSE: " .
            $response . "\n",
            FILE_APPEND
        );

        return false;
    }

    return json_decode($response, true);
}

function extractShopifyId($global_id)
{
    if (empty($global_id)) {
        return '';
    }

    $parts = explode('/', $global_id);

    return end($parts);
}
?>