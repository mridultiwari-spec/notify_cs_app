<?php
require_once dirname(__FILE__) . '/../config/db.php';
require_once dirname(__FILE__) . '/../app_config.php';
ini_set('max_execution_time', 0);
set_time_limit(0);
ignore_user_abort(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['sync_customers'])) {
    exit(json_encode(array('success' => false, 'message' => 'Invalid request')));
}

$shop = isset($_POST['shop']) ? $_POST['shop'] : '';
$segment_id = isset($_POST['segment_id']) ? $_POST['segment_id'] : '';
$local_segment_id = isset($_POST['local_segment_id']) ? $_POST['local_segment_id'] : '';

if (!$shop || !$segment_id || !$local_segment_id) {
    exit(json_encode(array('success' => false, 'message' => 'Missing required parameters')));
}

$pdo = getDatabaseConnection();
$customerSegmentTable = $prefix . "customer_segment";
$segmentCustomersInfoTable = $prefix . "segment_customers_info";
$tables = $prefix . "shopify_sms_notification_app";

$stmt = $pdo->prepare("SELECT shop, session_access_token AS oauth_token FROM $tables WHERE shop = :shop");
$stmt->execute(array(':shop' => $shop));
$tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
$pdo = null;

if (!$tokenData) {
    $failPdo = getDatabaseConnection();
    $stmt = $failPdo->prepare("UPDATE $customerSegmentTable SET sync_status = 'failed' WHERE id = ?");
    $stmt->execute(array($local_segment_id));
    $failPdo = null;

    header('Content-Type: application/json');
    echo json_encode(array('success' => false, 'message' => 'Shop not found'));

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    flush();
    exit;
}

$accessToken = $tokenData['oauth_token'];
$apiVersion = "2026-01";

$statusPdo = getDatabaseConnection();
$stmt = $statusPdo->prepare("UPDATE $customerSegmentTable SET sync_status = 'pending', sync_started_at = NOW() WHERE id = ?");
$stmt->execute(array($local_segment_id));
$statusPdo = null;

header('Content-Type: application/json');
header('Connection: close');
header('Content-Length: ' . strlen(json_encode(array('success' => true, 'status' => 'started'))));
echo json_encode(array(
    'success' => true,
    'message' => 'Sync started in background',
    'status' => 'processing'
));

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
flush();

try {
    error_log("[Sync-Start] Starting sync for shop: $shop, segment: $segment_id, local: $local_segment_id");
    $procPdo = getDatabaseConnection();
    $stmt = $procPdo->prepare("UPDATE $customerSegmentTable SET sync_status = 'processing' WHERE id = ?");
    $stmt->execute(array($local_segment_id));
    $procPdo = null;

    $allCustomers = array();
    $hasNextPage = true;
    $cursor = null;

    while ($hasNextPage) {
        $customerQuery = <<<GRAPHQL
query getCustomers(\$segmentId: ID!, \$cursor: String) {
  customerSegmentMembers(first: 250, segmentId: \$segmentId, after: \$cursor) {
    edges {
      node {
        id
        firstName
        lastName
        defaultEmailAddress {
          emailAddress
        }
        defaultPhoneNumber {
          phoneNumber
        }
        defaultAddress {
          address1
          address2
          city
          country
          countryCodeV2
          firstName
          lastName
          zip
        }
      }
    }
    pageInfo {
      hasNextPage
      endCursor
    }
  }
}
GRAPHQL;

        $ch = curl_init("https://$shop/admin/api/$apiVersion/graphql.json");
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => array(
                "Content-Type: application/json",
                "X-Shopify-Access-Token: $accessToken"
            ),
            CURLOPT_POSTFIELDS => json_encode(array(
                "query" => $customerQuery,
                "variables" => array(
                    "segmentId" => $segment_id,
                    "cursor" => $cursor
                )
            ))
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch) || $httpCode >= 400) {
            curl_close($ch);
            throw new Exception("API request failed: HTTP " . $httpCode);
        }

        curl_close($ch);
        $apiData = json_decode($response, true);

        if (!empty($apiData['data']['customerSegmentMembers']['edges'])) {
            foreach ($apiData['data']['customerSegmentMembers']['edges'] as $edge) {
                $allCustomers[] = $edge['node'];
            }
        }

        $pageInfo = null;
        if (isset($apiData['data']['customerSegmentMembers']['pageInfo'])) {
            $pageInfo = $apiData['data']['customerSegmentMembers']['pageInfo'];
        }

        if ($pageInfo && $pageInfo['hasNextPage']) {
            $cursor = $pageInfo['endCursor'];
        } else {
            $hasNextPage = false;
        }
        usleep(50000);
    }
    $batchSize = 50;
    $totalInserted = 0;

    for ($i = 0; $i < count($allCustomers); $i += $batchSize) {
        $batch = array_slice($allCustomers, $i, $batchSize);
        $values = array();
        $params = array();

        foreach ($batch as $customer) {
            $address1 = '';
            $address2 = '';
            $city = '';
            $country = '';
            $countryCode = '';
            $zip = '';

            if (isset($customer['defaultAddress'])) {
                $address1 = isset($customer['defaultAddress']['address1']) ? $customer['defaultAddress']['address1'] : '';
                $address2 = isset($customer['defaultAddress']['address2']) ? $customer['defaultAddress']['address2'] : '';
                $city = isset($customer['defaultAddress']['city']) ? $customer['defaultAddress']['city'] : '';
                $country = isset($customer['defaultAddress']['country']) ? $customer['defaultAddress']['country'] : '';
                $countryCode = isset($customer['defaultAddress']['countryCodeV2']) ? $customer['defaultAddress']['countryCodeV2'] : '';
                $zip = isset($customer['defaultAddress']['zip']) ? $customer['defaultAddress']['zip'] : '';
            }

            $values[] = "(?,?,?,?,?,?,?,?,?,?,?,?)";
            $params = array_merge($params, array(
                $segment_id,
                $customer['id'],
                isset($customer['firstName']) ? $customer['firstName'] : '',
                isset($customer['lastName']) ? $customer['lastName'] : '',
                isset($customer['defaultEmailAddress']['emailAddress']) ? $customer['defaultEmailAddress']['emailAddress'] : '',
                isset($customer['defaultPhoneNumber']['phoneNumber']) ? $customer['defaultPhoneNumber']['phoneNumber'] : '',
                $address1,
                $address2,
                $city,
                $country,
                $countryCode,
                $zip
            ));
        }
        $sql = "INSERT IGNORE INTO $segmentCustomersInfoTable 
                (segment_id, shopify_customer_id, first_name, last_name, email, phone, address1, address2, city, country, country_code, zip) 
                VALUES " . implode(',', $values);

        $batchPdo = getDatabaseConnection();
        $stmt = $batchPdo->prepare($sql);
        $stmt->execute($params);
        $totalInserted += count($batch);
        $stmt = null;
        $batchPdo = null;
        usleep(50000);
    }
    $finalPdo = getDatabaseConnection();
    $stmt = $finalPdo->prepare("UPDATE $customerSegmentTable SET sync_status = 'completed', sync_customer_count = ?, sync_completed_at = NOW() WHERE id = ?");
    $stmt->execute(array($totalInserted, $local_segment_id));
    $finalPdo = null;

    error_log("Background sync completed for segment $local_segment_id: $totalInserted customers synced");

} catch (Exception $e) {
    $errorPdo = getDatabaseConnection();
    $stmt = $errorPdo->prepare("UPDATE $customerSegmentTable SET sync_status = 'failed' WHERE id = ?");
    $stmt->execute(array($local_segment_id));
    $errorPdo = null;
    error_log("Background sync failed for segment $local_segment_id: " . $e->getMessage());
}