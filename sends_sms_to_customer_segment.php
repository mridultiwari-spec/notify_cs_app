<?php
include './config/db.php';
require_once __DIR__ . '/app_config.php';
function sendSMSToAllCustomers($shop){
    global $app_prefix;
    if (!$shop) {
        die("Shop not found");
    }
    $pdo = getDatabaseConnection();
    $prefix = $app_prefix;
    $tables = $prefix . "shopify_sms_notification_app";
    $stmt = $pdo->prepare("SELECT shop FROM $tables WHERE shop = :shop");
    $stmt->execute([':shop' => $shop]);
    $data = $stmt->fetch();
    if (!$data) {
        die("Shop not installed");
    }
    $batchSize = 1000;
    $offset = 0;
    while (true) {
        $stmt = $pdo->prepare("
            SELECT  sci.*, cs.sms FROM segment_customers_info sci
            JOIN customer_segment cs ON sci.segment_ref_id = cs.id
            ORDER BY sci.id ASC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($customers)) {
            break;
        }
        processBatch($customers);
        $offset += $batchSize;
    }
    return true;
}
function processBatch($customers){
    $mh = curl_multi_init();
    $handles = [];
    foreach ($customers as $customer) {
        $phone = isset($customer['phone']) ? $customer['phone'] : '';
        $message = isset($customer['sms']) ? $customer['sms'] : '';
        if (empty($phone) || empty($message)) continue;
        $url = ""; //API to send the SMS.
        $payload = json_encode([
            "phone" => $phone,
            "message" => $message
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json"
            ],
            CURLOPT_TIMEOUT => 10
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);
    foreach ($handles as $ch) {
        curl_multi_remove_handle($mh, $ch);
        //curl_close($ch);
    }
    curl_multi_close($mh);
}