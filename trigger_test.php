<?php
include 'send_sms_api.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/config/session_token_auth.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents("php://input"), true);
$countryCode = isset($data['country_code']) ? $data['country_code'] : '';
$phone = isset($data['phone']) ? $data['phone'] : '';
if (!$countryCode || !$phone) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing data'
    ]);
    exit;
}
try {
    $pdo = getDatabaseConnection();
    $sessionToken = get_bearer_token_php53();
    $tokenValidation = validate_shopify_session_token_php53($sessionToken, $api_secret, $api_key);
    if (!$tokenValidation['success']) {
        echo json_encode([
            'success' => false,
            'message' => $tokenValidation['error']
        ]);
        exit;
    }
    $shop = $tokenValidation['shop'];
    $_SESSION['shop'] = $shop;
    $testMessage = "This is a TEST SMS.";
    send_smstext(
        $countryCode,
        $phone,
        $testMessage,
        $shop,
        "test@example.com",
        "Test User"
    );
    echo json_encode([
        'success' => true,
        'message' => 'Test SMS sent successfully'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}