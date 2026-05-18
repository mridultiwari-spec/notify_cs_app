<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/config/session_token_auth.php';

$requiredConfig = array(
    'app_url' => isset($app_url) ? $app_url : '',
    'api_key' => isset($api_key) ? $api_key : '',
    'api_secret' => isset($api_secret) ? $api_secret : '',
    'prefix' => isset($prefix) ? $prefix : '',
    'app_prefix' => isset($app_prefix) ? $app_prefix : '',
    'db_host' => isset($db_host) ? $db_host : '',
    'db_user' => isset($db_user) ? $db_user : '',
    'db_name' => isset($db_name) ? $db_name : ''
);
$missingConfig = array();
foreach ($requiredConfig as $key => $value) {
    if ($value === null || $value === '') {
        $missingConfig[] = $key;
    }
}
if (!empty($missingConfig)) {
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Configuration Required</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                background: #f8fafc;
                margin: 0;
                padding: 24px;
                color: #111827;
            }

            .config-box {
                max-width: 760px;
                margin: 24px auto;
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 10px;
                padding: 20px;
            }

            h1 {
                margin-top: 0;
                font-size: 22px;
            }

            ul {
                margin: 10px 0 0;
            }

            li {
                margin: 6px 0;
                font-family: monospace;
            }

            p {
                margin: 8px 0;
            }
        </style>
    </head>

    <body>
        <div class="config-box">
            <h1>App configuration is incomplete</h1>
            <p>Please set these values in <code>notifycsapp/app_config.php</code>:</p>
            <ul>
                <?php foreach ($missingConfig as $key): ?>
                    <li><?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
            <p>You can use <code>notifycsapp/app_config.example.php</code> as template.</p>
        </div>
    </body>

    </html>
    <?php
    exit;
}

session_start();
$shop = isset($_GET['shop']) ? $_GET['shop'] : (isset($_SESSION['shop']) ? $_SESSION['shop'] : '');

$sessionToken = get_bearer_token_php53();
if ($sessionToken) {
    $validatedToken = validate_shopify_session_token_php53($sessionToken, $api_secret, $api_key);
    if (isset($validatedToken['success']) && $validatedToken['success']) {
        $shop = $validatedToken['shop'];
    }
}

if (!$shop) {
    die("Shop missing");
}

$_SESSION['shop'] = $shop;
session_write_close();

$pdo = getDatabaseConnection();

$table = $prefix . 'shopify_sms_notification_app';
$stmt = $pdo->prepare("SELECT shop FROM $table WHERE shop = :shop");
$stmt->execute(array(':shop' => $shop));
$data = $stmt->fetch();

if (!$data) {
    try {
        $insertStmt = $pdo->prepare("INSERT INTO $table (shop, install_date, status) VALUES (:shop, :install_date, :status)");
        $insertStmt->execute(array(
            ':shop' => $shop,
            ':install_date' => date('Y-m-d H:i:s'),
            ':status' => 'active'
        ));
        $stmt = $pdo->prepare("SELECT shop FROM $table WHERE shop = :shop");
        $stmt->execute(array(':shop' => $shop));
        $data = $stmt->fetch();
    } catch (Exception $e) {
        die("Unable to bootstrap shop installation record.");
    }

    if (!$data) {
        die("Unable to bootstrap shop installation record.");
    }
}

if (isset($_GET['bootstrap_token']) && $_GET['bootstrap_token'] == '1') {
    header('Content-Type: application/json');
    try {
        $bootstrapToken = get_bearer_token_php53();
        if (!$bootstrapToken && isset($_GET['id_token'])) {
            $bootstrapToken = $_GET['id_token'];
        }
        if ($bootstrapToken) {
            $bootstrapToken = preg_replace('/\s+/', '', trim($bootstrapToken));
        }
        $validatedBootstrap = validate_shopify_session_token_php53($bootstrapToken, $api_secret, $api_key);
        if (!isset($validatedBootstrap['success']) || !$validatedBootstrap['success']) {
            http_response_code(401);
            echo json_encode(array('success' => false, 'message' => isset($validatedBootstrap['error']) ? $validatedBootstrap['error'] : 'Missing session token'));
            exit;
        }

        $shop = $validatedBootstrap['shop'];
        $_SESSION['shop'] = $shop;

        $tokenState = get_valid_shop_access_token_php53($pdo, $table, $shop, $api_key, $api_secret, $bootstrapToken);
        if (!isset($tokenState['success']) || !$tokenState['success']) {
            http_response_code(500);
            echo json_encode(array(
                'success' => false,
                'message' => isset($tokenState['error']) ? $tokenState['error'] : 'Unable to bootstrap shop token',
                'shop' => $shop
            ));
            exit;
        }

        echo json_encode(array('success' => true, 'source' => isset($tokenState['source']) ? $tokenState['source'] : 'bootstrap'));
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(array(
            'success' => false,
            'message' => 'Bootstrap exception',
            'error' => $e->getMessage()
        ));
    }
    exit;
}

$topicAidMap = array(
    'orders/create' => 1,
    'orders/updated' => 2,
    'orders/cancelled' => 3,
    'refunds/create' => 4,
    'checkouts/update' => 5,
    'fulfillments/create' => 6,
    'orders/fulfilled' => 7,
    'fulfillments/update' => 8,
    'customers/update' => 9,
    'customers/enable' => 10,
);
// Handle abandoned checkout toggle requests (database-only, no webhook registration)
if (isset($_GET['abandoned_toggle']) && $_GET['abandoned_toggle'] == '1') {
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'error' => 'Invalid request'));
        exit;
    }

    $topic = isset($input['topic']) ? $input['topic'] : '';
    $action = isset($input['action']) ? (int) $input['action'] : 0;
    $channel = isset($input['channel']) ? $input['channel'] : '';

    // Only allow checkouts/update topic for abandoned checkout
    if ($topic !== 'checkouts/update') {
        http_response_code(400);
        echo json_encode(array('success' => false, 'error' => 'Invalid topic'));
        exit;
    }

    // Validate channel
    if ($channel !== 'sms' && $channel !== 'whatsapp') {
        http_response_code(400);
        echo json_encode(array('success' => false, 'error' => 'Invalid channel'));
        exit;
    }

    // Validate session
    $toggleShop = isset($_SESSION['shop']) ? $_SESSION['shop'] : '';
    $toggleToken = get_bearer_token_php53();
    if ($toggleToken) {
        $validatedToggleToken = validate_shopify_session_token_php53($toggleToken, $api_secret, $api_key);
        if (isset($validatedToggleToken['success']) && $validatedToggleToken['success']) {
            $toggleShop = $validatedToggleToken['shop'];
        }
    }

    if (!$toggleShop && isset($input['id_token']) && $input['id_token']) {
        $idToken = $input['id_token'];
        $validatedIdToken = validate_shopify_session_token_php53($idToken, $api_secret, $api_key);
        if (isset($validatedIdToken['success']) && $validatedIdToken['success']) {
            $toggleShop = $validatedIdToken['shop'];
        }
    }

    if (!$toggleShop) {
        http_response_code(401);
        echo json_encode(array('success' => false, 'error' => 'Unauthorized'));
        exit;
    }

    $togglePdo = getDatabaseConnection();
    $notificationTable = $prefix . 'shopify_sms_notification_App_Email_Notification';
    $aid = 5;

    $column = ($channel === 'sms') ? 'sms_enabled' : 'whatsapp_enabled';

    try {
        // Check if record exists
        $checkStmt = $togglePdo->prepare("SELECT id FROM $notificationTable WHERE shop = :shop AND aid = :aid");
        $checkStmt->execute(array(
            ':shop' => $toggleShop,
            ':aid' => $aid
        ));

        if ($checkStmt->fetch()) {
            // Update existing record
            $updateStmt = $togglePdo->prepare("UPDATE $notificationTable SET $column = :action WHERE shop = :shop AND aid = :aid");
            $updateStmt->execute(array(
                ':action' => $action,
                ':shop' => $toggleShop,
                ':aid' => $aid
            ));
        } else {
            // Insert new record
            $insertStmt = $togglePdo->prepare("INSERT INTO $notificationTable (shop, aid, $column) VALUES (:shop, :aid, :action)");
            $insertStmt->execute(array(
                ':shop' => $toggleShop,
                ':aid' => $aid,
                ':action' => $action
            ));
        }

        echo json_encode(array('success' => true));
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(array('success' => false, 'error' => 'Database error'));
    }
    exit;
}
if (!function_exists('getChannelStatus')) {
    function getChannelStatus($pdo, $shop, $aid, $prefix)
    {
        $table = $prefix . "shopify_sms_notification_App_Email_Notification";
        $stmt = $pdo->prepare("
            SELECT 
                MAX(sms_enabled) as sms,
                MAX(whatsapp_enabled) as wa
            FROM $table
            WHERE shop = :shop AND aid = :aid
        ");
        $stmt->execute(array(
            ':shop' => $shop,
            ':aid' => $aid
        ));

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return array(
            'sms' => (int) (isset($row['sms']) ? $row['sms'] : 0),
            'wa' => (int) (isset($row['wa']) ? $row['wa'] : 0)
        );
    }
}

$statuses = array();
foreach ($topicAidMap as $topic => $aid) {
    $statuses[$topic] = getChannelStatus($pdo, $shop, $aid, $prefix);
}

$orderSmsEnabled = $statuses['orders/create']['sms'];
$orderWhatsappEnabled = $statuses['orders/create']['wa'];

$editedSmsEnabled = $statuses['orders/updated']['sms'];
$editedWhatsappEnabled = $statuses['orders/updated']['wa'];

$cancelledSmsEnabled = $statuses['orders/cancelled']['sms'];
$cancelledWhatsappEnabled = $statuses['orders/cancelled']['wa'];

$refundSmsEnabled = $statuses['refunds/create']['sms'];
$refundWhatsappEnabled = $statuses['refunds/create']['wa'];

$abandonedSmsEnabled = $statuses['checkouts/update']['sms'];
$abandonedWhatsappEnabled = $statuses['checkouts/update']['wa'];

$fulfillmentRequestSmsEnabled = $statuses['fulfillments/create']['sms'];
$fulfillmentRequestWhatsappEnabled = $statuses['fulfillments/create']['wa'];

$shippingConfirmationSmsEnabled = $statuses['orders/fulfilled']['sms'];
$shippingConfirmationWhatsappEnabled = $statuses['orders/fulfilled']['wa'];

$shippingUpdateSmsEnabled = $statuses['fulfillments/update']['sms'];
$shippingUpdateWhatsappEnabled = $statuses['fulfillments/update']['wa'];

$customerAccountUpdateSmsEnabled = $statuses['customers/update']['sms'];
$customerAccountUpdateWhatsappEnabled = $statuses['customers/update']['wa'];

$customerWelcomeSmsEnabled = $statuses['customers/enable']['sms'];
$customerWelcomeWhatsappEnabled = $statuses['customers/enable']['wa'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="shopify-api-key" content="<?php echo htmlspecialchars($api_key, ENT_QUOTES, 'UTF-8'); ?>">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <link rel="stylesheet"
        href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/styles/style_index.css">
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <title>Templates</title>
    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 450px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #111827;
        }

        .close {
            color: #6b7280;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: #111827;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .modal .form-group {
            margin-bottom: 20px;
        }

        .modal .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #374151;
        }

        .modal .form-group select,
        .modal .form-group input {
            width: 90% !important;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }

        .modal .form-group select:focus,
        .modal .form-group input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }

        .modal .submit-btn {
            background-color: #3b82f6;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
        }

        .modal .submit-btn:hover {
            background-color: #2563eb;
        }

        .modal .cancel-btn {
            background-color: #6b7280;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
        }

        .modal .cancel-btn:hover {
            background-color: #4b5563;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        td:has(.test-btn) {
            text-align: center;
        }

        .test-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background-color: #3b82f6;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .test-btn .material-symbols-outlined {
            font-size: 14px;
        }

        .test-btn:hover {
            background-color: #2563eb;
            transform: translateY(-1px);
        }

        .test-btn:active {
            transform: translateY(0);
        }

        .searchable-select-wrapper {
            position: relative;
        }

        #country_search {
            width: 90%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 5px;
        }

        #country_search:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }

        #test_country_code {
            width: 94%;
            max-height: 200px;
            overflow-y: auto;
        }

        #test_country_code option {
            padding: 6px 8px;
        }
    </style>
</head>

<body>
    <?php include "layout/header.php"; ?>
    <div id="toast" class="toast">Template Saved Successfully</div>
    <div class="page-container">
        <div class="info-banner">
            <span class="info-icon">&#9432;</span>
            These notifications are automatically sent to the customer.
            Click on the notification template to edit the content.
        </div>
        <div class="tabs">
            <button class="tab-btn active" onclick="openTab('orders', this)">Orders</button>
            <button class="tab-btn" onclick="openTab('shipping', this)">Shipping</button>
            <button class="tab-btn" onclick="openTab('promo', this)">Promotional Messages</button>
            <button class="tab-btn" onclick="openTab('customer', this)">Customer</button>
            <button class="tab-btn" onclick="openTab('customer_segment', this)">Customer Segment</button>
        </div>
        <div id="orders" class="tab-content active">
            <div class="table-card">
                <div class="table-title">Orders</div>
                <table class="order-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th></th>
                            <th>Trigger Test</th>
                            <th class="action-header">SMS</th>
                            <th class="action-header">Whatsapp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><a
                                    href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/order_confirmation.php?shop=<?php echo $_SESSION['shop']; ?>">Order
                                    Confirmation</a></td>
                            <td>Sent automatically to the customer after they place their order.</td>
                            <td><button class="test-btn" onclick="openTestModal('order_confirmation')"><span
                                        class="material-symbols-outlined">play_arrow</span> Trigger Test</button></td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle sms-toggle" data-topic="orders/create"
                                        <?php echo $orderSmsEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle whatsapp-toggle"
                                        data-topic="orders/create" <?php echo $orderWhatsappEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <td><a
                                    href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/order_editted.php?shop=<?php echo $_SESSION['shop']; ?>">Order
                                    Edited</a></td>
                            <td>Sent to the customer after their order is edited.</td>
                            <td><button class="test-btn" onclick="openTestModal('order_edited')"><span
                                        class="material-symbols-outlined">play_arrow</span> Trigger Test</button></td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle sms-toggle" data-topic="orders/updated"
                                        <?php echo $editedSmsEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle whatsapp-toggle"
                                        data-topic="orders/updated" <?php echo $editedWhatsappEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <td><a
                                    href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/order_cancelled.php?shop=<?php echo $_SESSION['shop']; ?>">Order
                                    Cancelled</a></td>
                            <td>Sent automatically to the customer if their order is cancelled.</td>
                            <td><button class="test-btn" onclick="openTestModal('order_cancelled')"><span
                                        class="material-symbols-outlined">play_arrow</span> Trigger Test</button></td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle sms-toggle"
                                        data-topic="orders/cancelled" <?php echo $cancelledSmsEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle whatsapp-toggle"
                                        data-topic="orders/cancelled" <?php echo $cancelledWhatsappEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <td><a
                                    href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/order_refund.php?shop=<?php echo $_SESSION['shop']; ?>">Order
                                    Refund</a></td>
                            <td>Sent automatically to the customer if their order is refunded.</td>
                            <td><button class="test-btn" onclick="openTestModal('order_refund')"><span
                                        class="material-symbols-outlined">play_arrow</span> Trigger Test</button></td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle sms-toggle" data-topic="refunds/create"
                                        <?php echo $refundSmsEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle whatsapp-toggle"
                                        data-topic="refunds/create" <?php echo $refundWhatsappEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                        </tr>
                        <!-- <tr>
                            <td><a
                                    href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/abandoned_checkout.php?shop=<?php echo $_SESSION['shop']; ?>">Abandoned
                                    Checkout</a></td>
                            <td>Sent automatically to the customer if they leave checkout before they buy the items in
                                their cart.</td>
                            <td><button class="test-btn" onclick="openTestModal('abandoned_checkout')"><span
                                        class="material-symbols-outlined">play_arrow</span> Trigger Test</button></td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle sms-toggle"
                                        data-topic="checkouts/update" <?php echo $abandonedSmsEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle whatsapp-toggle"
                                        data-topic="checkouts/update" <?php echo $abandonedWhatsappEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                        </tr> -->
                        <tr>
                            <td><a
                                    href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/abandoned_checkout.php?shop=<?php echo $_SESSION['shop']; ?>">Abandoned
                                    Checkout</a></td>
                            <td>Sent automatically to the customer if they leave checkout before they buy the items in
                                their cart.</td>
                            <td><button class="test-btn" onclick="openTestModal('abandoned_checkout')"><span
                                        class="material-symbols-outlined">play_arrow</span> Trigger Test</button></td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="abandoned-toggle sms-toggle"
                                        data-topic="checkouts/update" <?php echo $abandonedSmsEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="abandoned-toggle whatsapp-toggle"
                                        data-topic="checkouts/update" <?php echo $abandonedWhatsappEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div id="shipping" class="tab-content">
            <div class="table-card">
                <div class="table-title">Shipping</div>
                <table class="order-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th></th>
                            <th>Trigger Test</th>
                            <th class="action-header">SMS</th>
                            <th class="action-header">Whatsapp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><a
                                    href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/fulfillment_request.php?shop=<?php echo $_SESSION['shop']; ?>">Fulfillment
                                    Request</a></td>
                            <td>Sent automatically to the customer when their order is fulfilled.</td>
                            <td><button class="test-btn" onclick="openTestModal('fulfillment_request')"><span
                                        class="material-symbols-outlined">play_arrow</span> Trigger Test</button></td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle sms-toggle"
                                        data-topic="fulfillments/create" <?php echo $fulfillmentRequestSmsEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle whatsapp-toggle"
                                        data-topic="fulfillments/create" <?php echo $fulfillmentRequestWhatsappEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <td><a
                                    href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/shipping_confirmation.php?shop=<?php echo $_SESSION['shop']; ?>">Shipping
                                    Confirmation</a></td>
                            <td>Sent automatically to the customer when their order is fulfilled.</td>
                            <td><button class="test-btn" onclick="openTestModal('shipping_confirmation')"><span
                                        class="material-symbols-outlined">play_arrow</span> Trigger Test</button></td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle sms-toggle"
                                        data-topic="orders/fulfilled" <?php echo $shippingConfirmationSmsEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle whatsapp-toggle"
                                        data-topic="orders/fulfilled" <?php echo $shippingConfirmationWhatsappEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <td><a
                                    href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/shipping_update.php?shop=<?php echo $_SESSION['shop']; ?>">Shipping
                                    Update</a></td>
                            <td>Sent automatically to the customer if their fulfilled order's tracking number is
                                updated.</td>
                            <td><button class="test-btn" onclick="openTestModal('shipping_update')"><span
                                        class="material-symbols-outlined">play_arrow</span> Trigger Test</button></td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle sms-toggle"
                                        data-topic="fulfillments/update" <?php echo $shippingUpdateSmsEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle whatsapp-toggle"
                                        data-topic="fulfillments/update" <?php echo $shippingUpdateWhatsappEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div id="customer" class="tab-content">
            <div class="table-card">
                <div class="table-title">Customer</div>
                <table class="order-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th></th>
                            <th>Trigger Test</th>
                            <th class="action-header">SMS</th>
                            <th class="action-header">Whatsapp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><a
                                    href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/account_invite.php?shop=<?php echo $_SESSION['shop']; ?>">Customer
                                    Account Invite</a></td>
                            <td>Sent to the customer with account activation instruction. You can edit this email before
                                you send it.</td>
                            <td><button class="test-btn" onclick="openTestModal('customer_account_invite')"><span
                                        class="material-symbols-outlined">play_arrow</span> Trigger Test</button></td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle sms-toggle"
                                        data-topic="customers/update" <?php echo $customerAccountUpdateSmsEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle whatsapp-toggle"
                                        data-topic="customers/update" <?php echo $customerAccountUpdateWhatsappEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <td><a
                                    href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/customer_welcome.php?shop=<?php echo $_SESSION['shop']; ?>">Customer
                                    Account Welcome</a></td>
                            <td>Sent automatically to the customer when they complete their account activation.</td>
                            <td><button class="test-btn" onclick="openTestModal('customer_welcome')"><span
                                        class="material-symbols-outlined">play_arrow</span> Trigger Test</button></td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle sms-toggle"
                                        data-topic="customers/enable" <?php echo $customerWelcomeSmsEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle whatsapp-toggle"
                                        data-topic="customers/enable" <?php echo $customerWelcomeWhatsappEnabled ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div id="customer_segment" class="tab-content">
            <div class="table-card">
                <div class="table-title">Customer Segment</div>
                <table class="order-table">
                    <tbody>
                        <tr>
                            <td><a
                                    href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/customer_segment.php?shop=<?php echo $_SESSION['shop']; ?>">Customer
                                    Segment</a></td>
                            <td>Sent to the customer-------</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div id="promo" class="tab-content">
            <div class="table-card">
                <div class="table-title">Promotional Messages</div>
                <table class="order-table">
                    <tbody>
                        <tr>
                            <td><a
                                    href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/order_value_based_message.php?shop=<?php echo $_SESSION['shop']; ?>">Based
                                    on Order Value</a></td>
                            <td>Sent to the customer when they buy Specific Order value.</td>
                        </tr>
                        <tr>
                            <td><a
                                    href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/product_tags_based_message.php?shop=<?php echo $_SESSION['shop']; ?>">Based
                                    on Product Tags</a></td>
                            <td>Sent automatically to the customer when they buys product of specific Tags.</td>
                        </tr>
                        <tr>
                            <td><a
                                    href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/time_period_based_message.php?shop=<?php echo $_SESSION['shop']; ?>">Based
                                    on Time Period</a></td>
                            <td>Sent automatically to the customer after specific time period like 1 hour, 1 day or 1
                                week after the order received.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- <div id="testModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Send Test Message</h3>
                <span class="close" onclick="closeTestModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Country Code</label>
                    <select id="test_country_code">
                        <option value="+91">India (+91)</option>
                        <option value="+1">USA (+1)</option>
                        <option value="+44">UK (+44)</option>
                        <option value="+61">Australia (+61)</option>
                        <option value="+86">China (+86)</option>
                        <option value="+81">Japan (+81)</option>
                        <option value="+49">Germany (+49)</option>
                        <option value="+33">France (+33)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" id="test_phone" placeholder="Enter phone number (e.g., 9876543210)" style="width: 80%;">
                    <small style="color: #6b7280; font-size: 12px;">Enter number without country code</small>
                </div>
            </div>
            <div class="modal-footer">
                <button class="cancel-btn" onclick="closeTestModal()">Cancel</button>
                <button class="submit-btn" onclick="sendTestFromModal()">Send Test</button>
            </div>
        </div>
    </div> -->
    <div id="testModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Send Test Message</h3>
                <span class="close" onclick="closeTestModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="form-group" style="position: relative;">
                    <label>Select Country Code : </label>
                    <div class="searchable-select-wrapper" style="position: relative;">
                        <input type="text" id="country_search" placeholder="Search country..."
                            style="width: 90%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; margin-bottom: 5px;"
                            onkeyup="filterCountries()" autocomplete="off">
                        <select id="test_country_code" size="6"
                            style="width: 94%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; overflow-y: auto;"
                            onchange="updateSearchInput()">
                            <option value="+93">Afghanistan (+93)</option>
                            <option value="+358">Aland Islands (+358)</option>
                            <option value="+355">Albania (+355)</option>
                            <option value="+213">Algeria (+213)</option>
                            <option value="+1684">American Samoa (+1684)</option>
                            <option value="+376">Andorra (+376)</option>
                            <option value="+244">Angola (+244)</option>
                            <option value="+1264">Anguilla (+1264)</option>
                            <option value="+672">Antarctica (+672)</option>
                            <option value="+1268">Antigua and Barbuda (+1268)</option>
                            <option value="+54">Argentina (+54)</option>
                            <option value="+374">Armenia (+374)</option>
                            <option value="+297">Aruba (+297)</option>
                            <option value="+61">Australia (+61)</option>
                            <option value="+43">Austria (+43)</option>
                            <option value="+994">Azerbaijan (+994)</option>
                            <option value="+1242">Bahamas (+1242)</option>
                            <option value="+973">Bahrain (+973)</option>
                            <option value="+880">Bangladesh (+880)</option>
                            <option value="+1246">Barbados (+1246)</option>
                            <option value="+375">Belarus (+375)</option>
                            <option value="+32">Belgium (+32)</option>
                            <option value="+501">Belize (+501)</option>
                            <option value="+229">Benin (+229)</option>
                            <option value="+1441">Bermuda (+1441)</option>
                            <option value="+975">Bhutan (+975)</option>
                            <option value="+591">Bolivia (+591)</option>
                            <option value="+599">Bonaire (+599)</option>
                            <option value="+387">Bosnia and Herzegovina (+387)</option>
                            <option value="+267">Botswana (+267)</option>
                            <option value="+55">Brazil (+55)</option>
                            <option value="+246">British Indian Ocean Territory (+246)</option>
                            <option value="+673">Brunei (+673)</option>
                            <option value="+359">Bulgaria (+359)</option>
                            <option value="+226">Burkina Faso (+226)</option>
                            <option value="+257">Burundi (+257)</option>
                            <option value="+855">Cambodia (+855)</option>
                            <option value="+237">Cameroon (+237)</option>
                            <option value="+1">Canada (+1)</option>
                            <option value="+238">Cape Verde (+238)</option>
                            <option value="+1345">Cayman Islands (+1345)</option>
                            <option value="+236">Central African Republic (+236)</option>
                            <option value="+235">Chad (+235)</option>
                            <option value="+56">Chile (+56)</option>
                            <option value="+86">China (+86)</option>
                            <option value="+61">Christmas Island (+61)</option>
                            <option value="+672">Cocos Islands (+672)</option>
                            <option value="+57">Colombia (+57)</option>
                            <option value="+269">Comoros (+269)</option>
                            <option value="+242">Congo (+242)</option>
                            <option value="+242">Congo DR (+242)</option>
                            <option value="+682">Cook Islands (+682)</option>
                            <option value="+506">Costa Rica (+506)</option>
                            <option value="+225">Cote d'Ivoire (+225)</option>
                            <option value="+385">Croatia (+385)</option>
                            <option value="+53">Cuba (+53)</option>
                            <option value="+599">Curacao (+599)</option>
                            <option value="+357">Cyprus (+357)</option>
                            <option value="+420">Czech Republic (+420)</option>
                            <option value="+45">Denmark (+45)</option>
                            <option value="+253">Djibouti (+253)</option>
                            <option value="+1767">Dominica (+1767)</option>
                            <option value="+1809">Dominican Republic (+1809)</option>
                            <option value="+593">Ecuador (+593)</option>
                            <option value="+20">Egypt (+20)</option>
                            <option value="+503">El Salvador (+503)</option>
                            <option value="+240">Equatorial Guinea (+240)</option>
                            <option value="+291">Eritrea (+291)</option>
                            <option value="+372">Estonia (+372)</option>
                            <option value="+251">Ethiopia (+251)</option>
                            <option value="+500">Falkland Islands (+500)</option>
                            <option value="+298">Faroe Islands (+298)</option>
                            <option value="+679">Fiji (+679)</option>
                            <option value="+358">Finland (+358)</option>
                            <option value="+33">France (+33)</option>
                            <option value="+594">French Guiana (+594)</option>
                            <option value="+689">French Polynesia (+689)</option>
                            <option value="+262">French Southern Territories (+262)</option>
                            <option value="+241">Gabon (+241)</option>
                            <option value="+220">Gambia (+220)</option>
                            <option value="+995">Georgia (+995)</option>
                            <option value="+49">Germany (+49)</option>
                            <option value="+233">Ghana (+233)</option>
                            <option value="+350">Gibraltar (+350)</option>
                            <option value="+30">Greece (+30)</option>
                            <option value="+299">Greenland (+299)</option>
                            <option value="+1473">Grenada (+1473)</option>
                            <option value="+590">Guadeloupe (+590)</option>
                            <option value="+1671">Guam (+1671)</option>
                            <option value="+502">Guatemala (+502)</option>
                            <option value="+44">Guernsey (+44)</option>
                            <option value="+224">Guinea (+224)</option>
                            <option value="+245">Guinea-Bissau (+245)</option>
                            <option value="+592">Guyana (+592)</option>
                            <option value="+509">Haiti (+509)</option>
                            <option value="+39">Holy See (+39)</option>
                            <option value="+504">Honduras (+504)</option>
                            <option value="+852">Hong Kong (+852)</option>
                            <option value="+36">Hungary (+36)</option>
                            <option value="+354">Iceland (+354)</option>
                            <option value="+91" selected>India (+91)</option>
                            <option value="+62">Indonesia (+62)</option>
                            <option value="+98">Iran (+98)</option>
                            <option value="+964">Iraq (+964)</option>
                            <option value="+353">Ireland (+353)</option>
                            <option value="+44">Isle of Man (+44)</option>
                            <option value="+972">Israel (+972)</option>
                            <option value="+39">Italy (+39)</option>
                            <option value="+1876">Jamaica (+1876)</option>
                            <option value="+81">Japan (+81)</option>
                            <option value="+44">Jersey (+44)</option>
                            <option value="+962">Jordan (+962)</option>
                            <option value="+7">Kazakhstan (+7)</option>
                            <option value="+254">Kenya (+254)</option>
                            <option value="+686">Kiribati (+686)</option>
                            <option value="+850">North Korea (+850)</option>
                            <option value="+82">South Korea (+82)</option>
                            <option value="+383">Kosovo (+383)</option>
                            <option value="+965">Kuwait (+965)</option>
                            <option value="+996">Kyrgyzstan (+996)</option>
                            <option value="+856">Laos (+856)</option>
                            <option value="+371">Latvia (+371)</option>
                            <option value="+961">Lebanon (+961)</option>
                            <option value="+266">Lesotho (+266)</option>
                            <option value="+231">Liberia (+231)</option>
                            <option value="+218">Libya (+218)</option>
                            <option value="+423">Liechtenstein (+423)</option>
                            <option value="+370">Lithuania (+370)</option>
                            <option value="+352">Luxembourg (+352)</option>
                            <option value="+853">Macau (+853)</option>
                            <option value="+389">Macedonia (+389)</option>
                            <option value="+261">Madagascar (+261)</option>
                            <option value="+265">Malawi (+265)</option>
                            <option value="+60">Malaysia (+60)</option>
                            <option value="+960">Maldives (+960)</option>
                            <option value="+223">Mali (+223)</option>
                            <option value="+356">Malta (+356)</option>
                            <option value="+692">Marshall Islands (+692)</option>
                            <option value="+596">Martinique (+596)</option>
                            <option value="+222">Mauritania (+222)</option>
                            <option value="+230">Mauritius (+230)</option>
                            <option value="+262">Mayotte (+262)</option>
                            <option value="+52">Mexico (+52)</option>
                            <option value="+691">Micronesia (+691)</option>
                            <option value="+373">Moldova (+373)</option>
                            <option value="+377">Monaco (+377)</option>
                            <option value="+976">Mongolia (+976)</option>
                            <option value="+382">Montenegro (+382)</option>
                            <option value="+1664">Montserrat (+1664)</option>
                            <option value="+212">Morocco (+212)</option>
                            <option value="+258">Mozambique (+258)</option>
                            <option value="+95">Myanmar (+95)</option>
                            <option value="+264">Namibia (+264)</option>
                            <option value="+674">Nauru (+674)</option>
                            <option value="+977">Nepal (+977)</option>
                            <option value="+31">Netherlands (+31)</option>
                            <option value="+687">New Caledonia (+687)</option>
                            <option value="+64">New Zealand (+64)</option>
                            <option value="+505">Nicaragua (+505)</option>
                            <option value="+227">Niger (+227)</option>
                            <option value="+234">Nigeria (+234)</option>
                            <option value="+683">Niue (+683)</option>
                            <option value="+672">Norfolk Island (+672)</option>
                            <option value="+1670">Northern Mariana Islands (+1670)</option>
                            <option value="+47">Norway (+47)</option>
                            <option value="+968">Oman (+968)</option>
                            <option value="+92">Pakistan (+92)</option>
                            <option value="+680">Palau (+680)</option>
                            <option value="+970">Palestine (+970)</option>
                            <option value="+507">Panama (+507)</option>
                            <option value="+675">Papua New Guinea (+675)</option>
                            <option value="+595">Paraguay (+595)</option>
                            <option value="+51">Peru (+51)</option>
                            <option value="+63">Philippines (+63)</option>
                            <option value="+64">Pitcairn (+64)</option>
                            <option value="+48">Poland (+48)</option>
                            <option value="+351">Portugal (+351)</option>
                            <option value="+1787">Puerto Rico (+1787)</option>
                            <option value="+974">Qatar (+974)</option>
                            <option value="+262">Reunion (+262)</option>
                            <option value="+40">Romania (+40)</option>
                            <option value="+7">Russia (+7)</option>
                            <option value="+250">Rwanda (+250)</option>
                            <option value="+590">Saint Barthelemy (+590)</option>
                            <option value="+290">Saint Helena (+290)</option>
                            <option value="+1869">Saint Kitts and Nevis (+1869)</option>
                            <option value="+1758">Saint Lucia (+1758)</option>
                            <option value="+590">Saint Martin (+590)</option>
                            <option value="+508">Saint Pierre and Miquelon (+508)</option>
                            <option value="+1784">Saint Vincent (+1784)</option>
                            <option value="+684">Samoa (+684)</option>
                            <option value="+378">San Marino (+378)</option>
                            <option value="+239">Sao Tome and Principe (+239)</option>
                            <option value="+966">Saudi Arabia (+966)</option>
                            <option value="+221">Senegal (+221)</option>
                            <option value="+381">Serbia (+381)</option>
                            <option value="+248">Seychelles (+248)</option>
                            <option value="+232">Sierra Leone (+232)</option>
                            <option value="+65">Singapore (+65)</option>
                            <option value="+721">Sint Maarten (+721)</option>
                            <option value="+421">Slovakia (+421)</option>
                            <option value="+386">Slovenia (+386)</option>
                            <option value="+677">Solomon Islands (+677)</option>
                            <option value="+252">Somalia (+252)</option>
                            <option value="+27">South Africa (+27)</option>
                            <option value="+500">South Georgia (+500)</option>
                            <option value="+211">South Sudan (+211)</option>
                            <option value="+34">Spain (+34)</option>
                            <option value="+94">Sri Lanka (+94)</option>
                            <option value="+249">Sudan (+249)</option>
                            <option value="+597">Suriname (+597)</option>
                            <option value="+47">Svalbard and Jan Mayen (+47)</option>
                            <option value="+268">Swaziland (+268)</option>
                            <option value="+46">Sweden (+46)</option>
                            <option value="+41">Switzerland (+41)</option>
                            <option value="+963">Syria (+963)</option>
                            <option value="+886">Taiwan (+886)</option>
                            <option value="+992">Tajikistan (+992)</option>
                            <option value="+255">Tanzania (+255)</option>
                            <option value="+66">Thailand (+66)</option>
                            <option value="+670">Timor-Leste (+670)</option>
                            <option value="+228">Togo (+228)</option>
                            <option value="+690">Tokelau (+690)</option>
                            <option value="+676">Tonga (+676)</option>
                            <option value="+1868">Trinidad and Tobago (+1868)</option>
                            <option value="+216">Tunisia (+216)</option>
                            <option value="+90">Turkey (+90)</option>
                            <option value="+7370">Turkmenistan (+7370)</option>
                            <option value="+1649">Turks and Caicos Islands (+1649)</option>
                            <option value="+688">Tuvalu (+688)</option>
                            <option value="+256">Uganda (+256)</option>
                            <option value="+380">Ukraine (+380)</option>
                            <option value="+971">United Arab Emirates (+971)</option>
                            <option value="+44">United Kingdom (+44)</option>
                            <option value="+1">United States (+1)</option>
                            <option value="+598">Uruguay (+598)</option>
                            <option value="+998">Uzbekistan (+998)</option>
                            <option value="+678">Vanuatu (+678)</option>
                            <option value="+58">Venezuela (+58)</option>
                            <option value="+84">Vietnam (+84)</option>
                            <option value="+1284">British Virgin Islands (+1284)</option>
                            <option value="+1340">US Virgin Islands (+1340)</option>
                            <option value="+681">Wallis and Futuna (+681)</option>
                            <option value="+212">Western Sahara (+212)</option>
                            <option value="+967">Yemen (+967)</option>
                            <option value="+260">Zambia (+260)</option>
                            <option value="+263">Zimbabwe (+263)</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" id="test_phone" placeholder="Enter phone number (e.g., 9876543210)"
                        style="width: 80%;">
                    <small style="color: #6b7280; font-size: 12px;">Enter number without country code</small>
                </div>
            </div>
            <div class="modal-footer">
                <button class="cancel-btn" onclick="closeTestModal()">Cancel</button>
                <button class="submit-btn" onclick="sendTestFromModal()">Send Test</button>
            </div>
        </div>
    </div>
    <script>
        // Pass PHP status to JavaScript
        var channelStatuses = {
            'orders/create': { sms: <?php echo $orderSmsEnabled ? 1 : 0; ?>, wa: <?php echo $orderWhatsappEnabled ? 1 : 0; ?> },
            'orders/updated': { sms: <?php echo $editedSmsEnabled ? 1 : 0; ?>, wa: <?php echo $editedWhatsappEnabled ? 1 : 0; ?> },
            'orders/cancelled': { sms: <?php echo $cancelledSmsEnabled ? 1 : 0; ?>, wa: <?php echo $cancelledWhatsappEnabled ? 1 : 0; ?> },
            'refunds/create': { sms: <?php echo $refundSmsEnabled ? 1 : 0; ?>, wa: <?php echo $refundWhatsappEnabled ? 1 : 0; ?> },
            'checkouts/update': { sms: <?php echo $abandonedSmsEnabled ? 1 : 0; ?>, wa: <?php echo $abandonedWhatsappEnabled ? 1 : 0; ?> },
            'fulfillments/create': { sms: <?php echo $fulfillmentRequestSmsEnabled ? 1 : 0; ?>, wa: <?php echo $fulfillmentRequestWhatsappEnabled ? 1 : 0; ?> },
            'orders/fulfilled': { sms: <?php echo $shippingConfirmationSmsEnabled ? 1 : 0; ?>, wa: <?php echo $shippingConfirmationWhatsappEnabled ? 1 : 0; ?> },
            'fulfillments/update': { sms: <?php echo $shippingUpdateSmsEnabled ? 1 : 0; ?>, wa: <?php echo $shippingUpdateWhatsappEnabled ? 1 : 0; ?> },
            'customers/update': { sms: <?php echo $customerAccountUpdateSmsEnabled ? 1 : 0; ?>, wa: <?php echo $customerAccountUpdateWhatsappEnabled ? 1 : 0; ?> },
            'customers/enable': { sms: <?php echo $customerWelcomeSmsEnabled ? 1 : 0; ?>, wa: <?php echo $customerWelcomeWhatsappEnabled ? 1 : 0; ?> }
        };

        function checkChannelStatus(templateType, callback) {
            const topicMap = {
                'order_confirmation': 'orders/create',
                'order_edited': 'orders/updated',
                'order_cancelled': 'orders/cancelled',
                'order_refund': 'refunds/create',
                'abandoned_checkout': 'checkouts/update',
                'fulfillment_request': 'fulfillments/create',
                'shipping_confirmation': 'orders/fulfilled',
                'shipping_update': 'fulfillments/update',
                'customer_account_invite': 'customers/update',
                'customer_welcome': 'customers/enable'
            };

            const topic = topicMap[templateType];
            if (topic && channelStatuses[topic]) {
                callback(channelStatuses[topic].sms === 1, channelStatuses[topic].wa === 1);
            } else {
                callback(false, false);
            }
        }
    </script>
    <script>
        let currentTemplateType = 'order_confirmation';
        document.addEventListener("DOMContentLoaded", function () {
            const params = new URLSearchParams(window.location.search);
            if (params.get("status") === "success") {
                const toast = document.getElementById("toast");
                toast.classList.add("show");
                setTimeout(() => {
                    toast.classList.remove("show");
                }, 1500);
                window.history.replaceState({}, document.title, window.location.pathname + "?shop=" + params.get("shop"));
            }
        });

        function openTab(tab, el = null) {
            let contents = document.querySelectorAll('.tab-content');
            let buttons = document.querySelectorAll('.tab-btn');
            contents.forEach(c => c.classList.remove('active'));
            buttons.forEach(b => b.classList.remove('active'));
            document.getElementById(tab).classList.add('active');
            if (el) {
                el.classList.add('active');
            } else {
                document.querySelectorAll('.tab-btn').forEach(btn => {
                    if (btn.getAttribute('onclick').includes(tab)) {
                        btn.classList.add('active');
                    }
                });
            }
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set("tab", tab);
            history.replaceState(null, "", "?" + urlParams.toString());
        }

        document.addEventListener("DOMContentLoaded", () => {
            const params = new URLSearchParams(window.location.search);
            const tab = params.get("tab");
            if (tab) {
                openTab(tab);
            }
        });

        function bootstrapSessionTokens() {
            function requestBootstrap(sessionToken) {
                if (!sessionToken) {
                    return;
                }
                fetch('?bootstrap_token=1&shop=<?php echo urlencode($shop); ?>&id_token=' + encodeURIComponent(sessionToken), {
                    method: 'GET',
                    headers: {
                        'Authorization': 'Bearer ' + sessionToken
                    }
                }).catch(() => { });
            }

            if (window.shopify && typeof window.shopify.idToken === 'function') {
                window.shopify.idToken()
                    .then((token) => requestBootstrap(token || ''))
                    .catch(() => { });
            }
        }

        document.addEventListener("DOMContentLoaded", function () {
            bootstrapSessionTokens();
        });

        document.querySelectorAll('.webhook-toggle').forEach(toggle => {
            toggle.addEventListener('change', function () {
                const topic = this.dataset.topic;
                const action = this.checked ? 1 : 0;
                const channel = this.classList.contains('sms-toggle') ? 'sms' : 'whatsapp';
                const toggleEl = this;

                function executeToggleRequest(sessionToken) {
                    const headers = {
                        'Content-Type': 'application/json'
                    };
                    if (sessionToken) {
                        headers['Authorization'] = 'Bearer ' + sessionToken;
                    }
                    return fetch('/notifycsapp/webhook_toggle.php', {
                        method: 'POST',
                        headers: headers,
                        body: JSON.stringify({
                            topic: topic,
                            action: action,
                            channel: channel,
                            id_token: sessionToken || ''
                        })
                    });
                }
                function runToggleFlow(sessionToken) {
                    executeToggleRequest(sessionToken)
                        .then(async (res) => {
                            let data = null;
                            try {
                                data = await res.json();
                            } catch (e) {
                                data = { error: 'Invalid server response' };
                            }
                            if (!res.ok) {
                                throw new Error((data && data.error) ? data.error : 'Request failed');
                            }
                            return data;
                        })
                        .then(data => {
                            if (data && data.error) {
                                shopify.toast.show(data.error, { isError: true, duration: 3000 });
                                toggleEl.checked = !toggleEl.checked;
                                return;
                            }
                            const statusText = action === 1 ? 'enabled' : 'disabled';
                            const channelText = channel === 'sms' ? 'SMS' : 'WhatsApp';
                            shopify.toast.show(channelText + ' webhook ' + statusText + ' successfully.', { duration: 3000 });

                            if (channelStatuses[topic]) {
                                if (channel === 'sms') {
                                    channelStatuses[topic].sms = action;
                                } else {
                                    channelStatuses[topic].wa = action;
                                }
                            }
                        })
                        .catch((e) => {
                            if (channel === 'sms') {
                                const errMessage = (e && e.message) ? e.message : 'Unable to update SMS webhook. Please try again.';
                                shopify.toast.show(errMessage, { isError: true, duration: 3000 });
                                toggleEl.checked = !toggleEl.checked;
                            }
                        });
                }

                if (window.shopify && typeof window.shopify.idToken === 'function') {
                    window.shopify.idToken()
                        .then((token) => runToggleFlow(token || ''))
                        .catch(() => runToggleFlow(''));
                } else {
                    runToggleFlow('');
                }
            });
        });

        function openTestModal(templateType = 'order_confirmation') {
            currentTemplateType = templateType;
            document.getElementById('testModal').style.display = 'block';
        }

        // function closeTestModal() {
        //     document.getElementById('testModal').style.display = 'none';
        //     document.getElementById('test_phone').value = '';
        // }
        function closeTestModal() {
            document.getElementById('testModal').style.display = 'none';
            document.getElementById('test_phone').value = '';
            document.getElementById('country_search').value = '';

            // Reset all options to visible
            var select = document.getElementById('test_country_code');
            var options = select.options;
            for (var i = 0; i < options.length; i++) {
                options[i].style.display = '';
            }
        }

        function sendTestFromModal() {
            const countryCode = document.getElementById('test_country_code').value;
            const phone = document.getElementById('test_phone').value;

            if (!phone) {
                shopify.toast.show("Please enter phone number", { isError: true, duration: 3000 });
                return;
            }

            const phoneRegex = /^\d{5,15}$/;
            if (!phoneRegex.test(phone)) {
                shopify.toast.show("Please enter a valid phone number (5-15 digits)", { isError: true, duration: 3000 });
                return;
            }

            checkChannelStatus(currentTemplateType, function (smsEnabled, whatsappEnabled) {
                if (!smsEnabled && !whatsappEnabled) {
                    shopify.toast.show("Please enable SMS or WhatsApp template first.", { isError: true, duration: 3000 });
                    return;
                }

                const sendButton = document.querySelector('#testModal .submit-btn');
                const originalText = sendButton.innerHTML;
                sendButton.disabled = true;
                sendButton.innerHTML = '<span class="loading-spinner"></span> Sending...';

                const endpointMap = {
                    'order_confirmation': '/notifycsapp/test_trigger/test_order_create.php',
                    'order_edited': '/notifycsapp/test_trigger/test_order_edit.php',
                    'order_cancelled': '/notifycsapp/test_trigger/test_order_cancel.php',
                    'order_refund': '/notifycsapp/test_trigger/test_refund_create.php',
                    'abandoned_checkout': '/notifycsapp/test_trigger/test_abandoned_checkout.php',
                    'fulfillment_request': '/notifycsapp/test_trigger/test_fulfillment.php',
                    'shipping_confirmation': '/notifycsapp/test_trigger/test_shipping_confirm.php',
                    'shipping_update': '/notifycsapp/test_trigger/test_shipping_update.php',
                    'customer_account_invite': '/notifycsapp/test_trigger/test_customer_update.php',
                    'customer_welcome': '/notifycsapp/test_trigger/test_customer_welcome.php'
                };

                const endpoint = endpointMap[currentTemplateType] || '/test_trigger/test_order_create.php';

                fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        country_code: countryCode,
                        phone: phone,
                        shop: "<?php echo $_SESSION['shop']; ?>"
                    })
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data.success) {
                            closeTestModal();
                            shopify.toast.show('✓ Test triggered successfully! Check the status in App Logs.', { duration: 3000 });
                        } else {
                            shopify.toast.show(data.message || "Error sending test message", { isError: true, duration: 3000 });
                        }
                        sendButton.disabled = false;
                        sendButton.innerHTML = originalText;
                    })
                    .catch(function (err) {
                        console.error(err);
                        shopify.toast.show("Network error. Please try again.", { isError: true, duration: 3000 });
                        sendButton.disabled = false;
                        sendButton.innerHTML = originalText;
                    });
            });
        }

        window.onclick = function (event) {
            const modal = document.getElementById('testModal');
            if (event.target == modal) {
                closeTestModal();
            }
        }
        function filterCountries() {
            var searchInput = document.getElementById('country_search');
            var select = document.getElementById('test_country_code');
            var filter = searchInput.value.toLowerCase();
            var options = select.options;

            for (var i = 0; i < options.length; i++) {
                var optionText = options[i].text.toLowerCase();
                var optionValue = options[i].value.toLowerCase();

                if (optionText.indexOf(filter) > -1 || optionValue.indexOf(filter) > -1) {
                    options[i].style.display = '';
                } else {
                    options[i].style.display = 'none';
                }
            }

            for (var j = 0; j < options.length; j++) {
                if (options[j].style.display !== 'none') {
                    if (filter.length > 0) {
                        options[j].scrollIntoView(false);
                    }
                    break;
                }
            }
        }
        function updateSearchInput() {
            var select = document.getElementById('test_country_code');
            var searchInput = document.getElementById('country_search');
            var selectedOption = select.options[select.selectedIndex];
            if (selectedOption) {
                searchInput.value = selectedOption.text;
            }
        }
        function openTestModal(templateType) {
            currentTemplateType = templateType;
            document.getElementById('testModal').style.display = 'block';
            var select = document.getElementById('test_country_code');
            var searchInput = document.getElementById('country_search');
            var selectedOption = select.options[select.selectedIndex];
            if (selectedOption) {
                searchInput.value = selectedOption.text;
            }

            setTimeout(function () {
                searchInput.focus();
                searchInput.select();
            }, 100);
        }

        document.querySelectorAll('.abandoned-toggle').forEach(function (toggle) {
            toggle.addEventListener('change', function () {
                const topic = this.dataset.topic;
                const action = this.checked ? 1 : 0;
                const channel = this.classList.contains('sms-toggle') ? 'sms' : 'whatsapp';
                const toggleEl = this;

                function executeAbandonedToggle(sessionToken) {
                    const headers = {
                        'Content-Type': 'application/json'
                    };
                    if (sessionToken) {
                        headers['Authorization'] = 'Bearer ' + sessionToken;
                    }
                   
                    return fetch('?abandoned_toggle=1&shop=<?php echo urlencode($shop); ?>', {
                        method: 'POST',
                        headers: headers,
                        body: JSON.stringify({
                            topic: topic,
                            action: action,
                            channel: channel,
                            id_token: sessionToken || ''
                        })
                    });
                }

                function runAbandonedFlow(sessionToken) {
                    executeAbandonedToggle(sessionToken)
                        .then(function (res) {
                            return res.json().then(function (data) {
                                if (!res.ok) {
                                    throw new Error((data && data.error) ? data.error : 'Request failed');
                                }
                                return data;
                            });
                        })
                        .then(function (data) {
                            if (data && data.error) {
                                shopify.toast.show(data.error, { isError: true, duration: 3000 });
                                toggleEl.checked = !toggleEl.checked;
                                return;
                            }
                            const statusText = action === 1 ? 'enabled' : 'disabled';
                            const channelText = channel === 'sms' ? 'SMS' : 'WhatsApp';
                            shopify.toast.show('Abandoned checkout ' + channelText + ' ' + statusText + ' successfully.', { duration: 3000 });

                            if (channelStatuses[topic]) {
                                if (channel === 'sms') {
                                    channelStatuses[topic].sms = action;
                                } else {
                                    channelStatuses[topic].wa = action;
                                }
                            }
                        })
                        .catch(function (e) {
                            const errMessage = (e && e.message) ? e.message : 'Unable to update abandoned checkout status. Please try again.';
                            shopify.toast.show(errMessage, { isError: true, duration: 3000 });
                            toggleEl.checked = !toggleEl.checked;
                        });
                }

                if (window.shopify && typeof window.shopify.idToken === 'function') {
                    window.shopify.idToken()
                        .then(function (token) {
                            runAbandonedFlow(token || '');
                        })
                        .catch(function () {
                            runAbandonedFlow('');
                        });
                } else {
                    runAbandonedFlow('');
                }
            });
        });
    </script>
    <?php if (!empty($_SESSION['success'])): ?>
        <div id="toast" class="toast">
            <?= $_SESSION['success']; ?>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
</body>

</html>