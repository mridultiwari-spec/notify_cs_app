<?php
function fetchProductImageAndUpdateDb($pdo, $shop, $oauth_token, $product_id, $aid, $media_source, $prefix)
{
    $result = array(
        'success' => false,
        'message' => '',
        'media_url' => null
    );

    if (!$pdo || !$shop || !$oauth_token || !$product_id || !$aid || !$media_source || !$prefix) {
        $result['message'] = 'Missing required parameters';
        return $result;
    }

    $logFile = dirname(__FILE__) . '/prod_img.txt';

    try {
        $gid = "gid://shopify/Product/" . $product_id;
        $query = '{
  product(id: "' . $gid . '") {
    images(first: 1) {
      edges {
        node {
          url
        }
      }
    }
  }
}';

        $url = "https://" . $shop . "/admin/api/2026-01/graphql.json";

        $headers = array(
            "Content-Type: application/json",
            "X-Shopify-Access-Token: " . $oauth_token
        );

        $payload = json_encode(array('query' => $query));

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $result['message'] = "cURL Error: " . curl_error($ch);
            curl_close($ch);
            file_put_contents($logFile, "ERROR: " . $result['message'] . "\n", FILE_APPEND);
            return $result;
        }

        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code >= 400) {
            $result['message'] = "API Error: HTTP " . $http_code . " - Response: " . substr($response, 0, 500);
            file_put_contents($logFile, "ERROR: " . $result['message'] . "\n", FILE_APPEND);
            return $result;
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            $jsonError = '';
            switch (json_last_error()) {
                case JSON_ERROR_NONE:
                    $jsonError = 'No error';
                    break;
                case JSON_ERROR_DEPTH:
                    $jsonError = 'Maximum stack depth exceeded';
                    break;
                case JSON_ERROR_STATE_MISMATCH:
                    $jsonError = 'State mismatch (invalid or malformed JSON)';
                    break;
                case JSON_ERROR_CTRL_CHAR:
                    $jsonError = 'Control character error, possibly incorrectly encoded';
                    break;
                case JSON_ERROR_SYNTAX:
                    $jsonError = 'Syntax error';
                    break;
                case JSON_ERROR_UTF8:
                    $jsonError = 'Malformed UTF-8 characters, possibly incorrectly encoded';
                    break;
                default:
                    $jsonError = 'Unknown JSON error code: ' . json_last_error();
                    break;
            }
            $result['message'] = "JSON decode error: " . $jsonError;
            file_put_contents($logFile, "ERROR: " . $result['message'] . "\n", FILE_APPEND);
            return $result;
        }

        if (isset($decoded['errors'])) {
            $errorMsg = isset($decoded['errors'][0]['message']) ? $decoded['errors'][0]['message'] : 'Unknown GraphQL error';
            $result['message'] = "GraphQL Error: " . $errorMsg;
            file_put_contents($logFile, "ERROR: " . $result['message'] . "\n", FILE_APPEND);
            return $result;
        }

        if (isset($decoded['data']['product']['images']['edges'][0]['node']['url'])) {
            $media_url = $decoded['data']['product']['images']['edges'][0]['node']['url'];
            $result['media_url'] = $media_url;

            $table_name = $prefix . "shopify_sms_notification_App_Email_Notification";

            try {
                $checkStmt = $pdo->prepare("SELECT id FROM `" . $table_name . "` WHERE aid = :aid AND shop = :shop");
                $checkStmt->execute(array(
                    ':aid' => $aid,
                    ':shop' => $shop
                ));

                if ($checkStmt->fetch()) {
                    $updateStmt = $pdo->prepare("UPDATE `" . $table_name . "` SET media_url = :media_url WHERE aid = :aid AND shop = :shop");
                    $updateStmt->execute(array(
                        ':media_url' => $media_url,
                        ':aid' => $aid,
                        ':shop' => $shop
                    ));

                    $result['success'] = true;
                    $result['message'] = "Successfully updated product image URL in database";
                    file_put_contents($logFile, "SUCCESS: Updated media_url for shop=$shop, aid=$aid, url=$media_url\n", FILE_APPEND);
                } else {
                    $insertStmt = $pdo->prepare("INSERT INTO `" . $table_name . "` (aid, shop, media_url, media_source) VALUES (:aid, :shop, :media_url, :media_source)");
                    $insertStmt->execute(array(
                        ':aid' => $aid,
                        ':shop' => $shop,
                        ':media_url' => $media_url,
                        ':media_source' => $media_source
                    ));

                    $result['success'] = true;
                    $result['message'] = "Successfully inserted product image URL into database";
                    file_put_contents($logFile, "SUCCESS: Inserted media_url for shop=$shop, aid=$aid, url=$media_url\n", FILE_APPEND);
                }
            } catch (PDOException $e) {
                $result['message'] = "Database error: " . $e->getMessage();
                file_put_contents($logFile, "ERROR: Database update failed - " . $e->getMessage() . "\n", FILE_APPEND);
            }
        } else {
            $result['message'] = "No product image found for product ID: " . $product_id;
            file_put_contents($logFile, "WARNING: No image found for product_id=$product_id, shop=$shop\n", FILE_APPEND);
        }

    } catch (Exception $e) {
        $result['message'] = "Error: " . $e->getMessage();
        file_put_contents($logFile, "EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
    }

    return $result;
}
function getProductImageForNotification($pdo, $shop, $oauth_token, $config, $product_id, $prefix, $aid = 1)
{
    $media_source = isset($config['media_source']) ? $config['media_source'] : '';
    $media_type = isset($config['media_type']) ? $config['media_type'] : '';
    $media_url = isset($config['media_url']) ? $config['media_url'] : '';

    if ($media_source == 'dynamic' && $media_type == 'image' && !empty($product_id)) {

        $fetchResult = fetchProductImageAndUpdateDb($pdo, $shop, $oauth_token, $product_id, $aid, $media_source, $prefix);

        if ($fetchResult['success'] && !empty($fetchResult['media_url'])) {
            return $fetchResult['media_url'];
        }

        return $media_url;
    }
    return $media_url;
}
?>