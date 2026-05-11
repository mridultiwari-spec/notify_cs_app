<?php
/**
 * Combined function to fetch product image and update database - PHP 5.4 compatible
 * 
 * @param mysqli $conn Database connection
 * @param string $shop Shopify shop domain
 * @param string $oauth_token Shopify access token
 * @param int $product_id Product ID
 * @param int $aid Dynamic aid value for database
 * @param string $media_source Dynamic media_source for database

 * @return array Result with status and message
 */
function fetchProductImageAndUpdateDb($conn, $shop, $oauth_token, $product_id, $aid, $media_source) {
    // Initialize result array using PHP 5.4 compatible syntax
    $result = array(
        'success' => false,
        'message' => '',
        'media_url' => null
    );
    
    // Validate required parameters
    if (!$conn || !$shop || !$oauth_token || !$product_id || !$aid || !$media_source) {
        $result['message'] = 'Missing required parameters';
        return $result;
    }
    
    try {
        // Convert product ID to GraphQL global ID
        $gid = "gid://shopify/Product/" . $product_id;
        
        // Create GraphQL query to fetch product image
        $query = '{ product(id: "' . $gid . '") { images(first: 1) { edges { node { originalSrc } } } } }';
        
        // Make GraphQL request
        $url = "https://{$shop}/admin/api/2023-10/graphql.json";
        
        // Use PHP 5.4 compatible array syntax
        $headers = array(
            "Content-Type: application/json",
            "X-Shopify-Access-Token: " . $oauth_token
        );
        
        $payload = json_encode(array('query' => $query));
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For development only
        
        $response = curl_exec($ch);
        
        // Handle cURL errors
        if (curl_errno($ch)) {
            $result['message'] = "cURL Error: " . curl_error($ch);
            curl_close($ch);
            return $result;
        }
        
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Handle HTTP errors
        if ($http_code >= 400) {
            $result['message'] = "API Error: HTTP " . $http_code;
            return $result;
        }
        
        // Parse response
        $decoded = json_decode($response, true);
        if ($decoded === null) {
            $jsonErrorMessage = function_exists('json_last_error_msg') ? json_last_error_msg() : ('JSON error code: ' . json_last_error());
            $result['message'] = "JSON decode error: " . $jsonErrorMessage;
            return $result;
        }
        
        // Extract image URL
        if (isset($decoded['data']['product']['images']['edges'][0]['node']['originalSrc'])) {
            $media_url = $decoded['data']['product']['images']['edges'][0]['node']['originalSrc'];
            $result['media_url'] = $media_url;
            
            // // Update database
            // $escaped_media_url = mysqli_real_escape_string($conn, $media_url);
            // $escaped_shop = mysqli_real_escape_string($conn, $shop);
            // $escaped_aid = mysqli_real_escape_string($conn, $aid);
            // $escaped_media_source = mysqli_real_escape_string($conn, $media_source);
            // $escaped_table = mysqli_real_escape_string($conn, $table_name);
            
            // $update_query = "UPDATE `$escaped_table` 
            //                SET media_url = '$escaped_media_url' 
            //                WHERE aid = '$escaped_aid' AND media_source = '$escaped_media_source' AND shop = '$escaped_shop'";
            
            if (!empty($media_url)) {
                $result['success'] = true;
                $result['message'] = "Successfully updated product image URL";
            // } else {
            //     $result['message'] = "Database update failed: " . mysqli_error($conn);
            }
        } else {
            $result['message'] = "No product image found";
        }
        
    // In PHP 5.4, we might not have json_last_error_msg(), so we handle this separately
    } catch (Exception $e) {
        $result['message'] = "Error: " . $e->getMessage();
    }
    file_put_contents('prod_img.txt', json_encode($result));
    return $result;
}

// Here's how to use the function within your existing code:

// First check the database for the media source setting

?>