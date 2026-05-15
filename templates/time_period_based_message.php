<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app_config.php';
require_once __DIR__ . '/../config/session_token_auth.php';

function send_json_response($payload, $statusCode = 200)
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

$shop = isset($_SESSION['shop']) ? $_SESSION['shop'] : (isset($_POST['shop']) ? $_POST['shop'] : (isset($_GET['shop']) ? $_GET['shop'] : ''));
if (!$shop) {
    send_json_response(array('success' => false, 'message' => 'Shop not found'), 400);
}
session_write_close();
$pdo = getDatabaseConnection();
$prefix = $app_prefix;
$table = "$prefix" . "shopify_sms_notification_App_Email_Notification";
$tables = "$prefix" . "shopify_sms_notification_app";

// Handle toggle update
if (isset($_POST['toggle_update']) && isset($_POST['record_id']) && isset($_POST['channel']) && isset($_POST['enabled'])) {
    $record_id = $_POST['record_id'];
    $channel = $_POST['channel'];
    $enabled = $_POST['enabled'] ? '1' : '0';

    $column = ($channel === 'sms') ? 'sms_enabled' : 'whatsapp_enabled';

    try {
        $stmt = $pdo->prepare("UPDATE $table SET $column = :enabled WHERE id = :id AND shop = :shop");
        $stmt->execute(array(
            ':enabled' => $enabled,
            ':id' => $record_id,
            ':shop' => $shop
        ));
        send_json_response(array('success' => true, 'message' => ucfirst($channel) . ' ' . ($enabled ? 'enabled' : 'disabled') . ' successfully'));
    } catch (Exception $e) {
        send_json_response(array('success' => false, 'message' => $e->getMessage()));
    }
}

if (isset($_GET['delete_id'])) {
    try {
        $id = $_GET['delete_id'];
        $stmt = $pdo->prepare("
            DELETE FROM $table 
            WHERE id = :id AND shop = :shop
        ");
        $stmt->execute(array(
            ':id' => $id,
            ':shop' => $shop
        ));
        send_json_response(array("status" => "deleted"));
    } catch (Exception $e) {
        send_json_response(array("error" => $e->getMessage()), 500);
    }
}

if (isset($_GET['edit_id'])) {
    $id = $_GET['edit_id'];

    $stmt = $pdo->prepare("
        SELECT * FROM $table 
        WHERE id = :id AND shop = :shop
    ");
    $stmt->execute(array(
        ':id' => $id,
        ':shop' => $shop
    ));

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['whatsapp'])) {
        $row['whatsapp'] = json_decode($row['whatsapp'], true);
    }
    if (!empty($row['sms_variables'])) {
        $row['sms_variables'] = json_decode($row['sms_variables'], true);
    }
    if (!empty($row['cta_url'])) {
        $row['cta_url'] = json_decode($row['cta_url'], true);
    }

    send_json_response($row);
}

if (isset($_POST['get_template_data']) && isset($_POST['id'])) {
    $id = $_POST['id'];
    try {
        $stmt = $pdo->prepare("SELECT sms, template_name, whatsapp FROM $table WHERE id = :id AND shop = :shop");
        $stmt->execute(array(':id' => $id, ':shop' => $shop));
        $templateData = $stmt->fetch(PDO::FETCH_ASSOC);

        $response = array('success' => true);
        if ($templateData) {
            $response['sms'] = $templateData['sms'];
            $response['template_name'] = $templateData['template_name'];
            if (!empty($templateData['whatsapp'])) {
                $response['whatsapp'] = json_decode($templateData['whatsapp'], true);
            } else {
                $response['whatsapp'] = array();
            }
        }
        send_json_response($response);
    } catch (Exception $e) {
        send_json_response(array('success' => false, 'message' => $e->getMessage()));
    }
}

if ($_SERVER["REQUEST_METHOD"] === 'POST') {
    global $app_url;
    $token = get_bearer_token_php53();
    $tokenCheck = validate_shopify_session_token_php53($token, $api_secret, $api_key);
    if (!$tokenCheck['success']) {
        send_json_response(array('success' => false, 'message' => $tokenCheck['error']));
    }
    $shop = $tokenCheck['shop'];
    $_SESSION['shop'] = $shop;
    $tokenState = get_valid_shop_access_token_php53($pdo, $tables, $shop, $api_key, $api_secret, $token);

    if (!$tokenState['success']) {
        send_json_response(array('success' => false, 'message' => $tokenState['error']));
    }

    try {
        $id = isset($_POST['id']) ? $_POST['id'] : '';
        $aid = isset($_POST['aid']) ? $_POST['aid'] : '13';
        $order_name = isset($_POST['order_name']) ? $_POST['order_name'] : '';
        $order_condition = isset($_POST['order_condition']) ? $_POST['order_condition'] : '';
        $timeinterval = isset($_POST['timeinterval']) ? $_POST['timeinterval'] : '';

        if (isset($_POST['sms_tab_submit'])) {
            $sms = isset($_POST['sms']) ? $_POST['sms'] : '';
            $template_name_sms = isset($_POST['sms_template_name']) ? trim($_POST['sms_template_name']) : '';

            $variables = array();
            for ($i = 1; $i <= 6; $i++) {
                $key = isset($_POST["key_$i"]) ? trim($_POST["key_$i"]) : '';
                $value = isset($_POST["var$i"]) ? trim($_POST["var$i"]) : '';
                if (!empty($key) && !empty($value)) {
                    $variables[$key] = $value;
                }
            }
            $sms_variables = json_encode($variables);

            if (!empty($id)) {
                $stmt = $pdo->prepare("
                    UPDATE $table SET
                        order_name = :order_name,
                        order_condition = :order_condition,
                        timeinterval = :timeinterval,
                        sms = :sms,
                        template_name = :template_name,
                        sms_variables = :sms_variables
                    WHERE id = :id AND shop = :shop AND aid = :aid
                ");
                $stmt->execute(array(
                    ':id' => $id,
                    ':shop' => $shop,
                    ':aid' => $aid,
                    ':order_name' => $order_name,
                    ':order_condition' => $order_condition,
                    ':timeinterval' => $timeinterval,
                    ':sms' => $sms,
                    ':template_name' => $template_name_sms,
                    ':sms_variables' => $sms_variables
                ));
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO $table 
                    (aid, shop, order_name, order_condition, timeinterval, sms, template_name, sms_variables)
                    VALUES 
                    (:aid, :shop, :order_name, :order_condition, :timeinterval, :sms, :template_name, :sms_variables)
                ");
                $stmt->execute(array(
                    ':aid' => $aid,
                    ':shop' => $shop,
                    ':order_name' => $order_name,
                    ':order_condition' => $order_condition,
                    ':timeinterval' => $timeinterval,
                    ':sms' => $sms,
                    ':template_name' => $template_name_sms,
                    ':sms_variables' => $sms_variables
                ));
            }

            send_json_response(array(
                'success' => true,
                'message' => 'SMS template saved successfully.',
                'redirect_url' => $app_url . "/templates/time_period_based_message.php?shop=" . urlencode($shop)
            ));

        } elseif (isset($_POST['whatsapp_tab_submit'])) {
            $media_type = isset($_POST['media_type']) ? $_POST['media_type'] : 'text';
            $media_source_type = isset($_POST['media_source_type']) ? $_POST['media_source_type'] : 'url';
            $media_source = null;
            $media_url = null;

            if ($media_type !== 'text') {
                if ($media_source_type === 'url') {
                    if (!empty($_POST['media_url'])) {
                        $media_source = 'input';
                        $media_url = trim($_POST['media_url']);
                    }
                } elseif ($media_source_type === 'file') {
                    if (isset($_FILES['media_file']) && $_FILES['media_file']['error'] === 0) {
                        $uploadDir = dirname(__FILE__) . "/uploads/";
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0777, true);
                        }

                        $fileExtension = strtolower(pathinfo($_FILES["media_file"]["name"], PATHINFO_EXTENSION));
                        $fileName = time() . "_" . preg_replace(
                            "/[^a-zA-Z0-9._-]/",
                            "",
                            pathinfo($_FILES["media_file"]["name"], PATHINFO_FILENAME)
                        ) . "." . $fileExtension;

                        $targetPath = $uploadDir . $fileName;
                        if (move_uploaded_file($_FILES["media_file"]["tmp_name"], $targetPath)) {
                            $media_source = 'file';
                            $media_url = rtrim($app_url, '/') . "/templates/uploads/" . $fileName;
                        }
                    }
                } elseif ($media_source_type === 'dynamic') {
                    $media_source = 'dynamic';
                    $media_url = null;
                }
            }

            $button_type1 = isset($_POST['button_type1']) ? $_POST['button_type1'] : '';
            $button_text1 = isset($_POST['button_text1']) ? $_POST['button_text1'] : '';
            $button_url1 = isset($_POST['button_url1']) ? $_POST['button_url1'] : '';
            $button_type2 = isset($_POST['button_type2']) ? $_POST['button_type2'] : '';
            $button_text2 = isset($_POST['button_text2']) ? $_POST['button_text2'] : '';
            $button_url2 = isset($_POST['button_url2']) ? $_POST['button_url2'] : '';
            $button_type3 = isset($_POST['button_type3']) ? $_POST['button_type3'] : '';
            $button_text3 = isset($_POST['button_text3']) ? $_POST['button_text3'] : '';
            $button_url3 = isset($_POST['button_url3']) ? $_POST['button_url3'] : '';

            $cta_urls = array();
            if ($button_type1 === 'cta' && !empty($button_url1)) {
                $cta_urls['button1'] = $button_url1;
            }
            if ($button_type2 === 'cta' && !empty($button_url2)) {
                $cta_urls['button2'] = $button_url2;
            }
            if ($button_type3 === 'cta' && !empty($button_url3)) {
                $cta_urls['button3'] = $button_url3;
            }
            $cta_url_json = !empty($cta_urls) ? json_encode($cta_urls) : null;
            $template_name = isset($_POST['whatsapp_template_name']) ? $_POST['whatsapp_template_name'] : '';
            $variable_headers = isset($_POST['variable_headers']) ? $_POST['variable_headers'] : array();
            $variable_body = isset($_POST['variable_body']) ? $_POST['variable_body'] : array();
            $variable_headers = array_values(array_filter($variable_headers));
            $variable_body = array_values(array_filter($variable_body));

            $whatsapp_data = json_encode(array(
                "template_name" => $template_name,
                "variable_headers" => $variable_headers,
                "variable_body" => $variable_body
            ));

            if (!empty($id)) {
                $stmt = $pdo->prepare("
                    UPDATE $table SET
                        order_name = :order_name,
                        order_condition = :order_condition,
                        timeinterval = :timeinterval,
                        media_type = :media_type,
                        media_url = :media_url,
                        media_source = :media_source,
                        button_type = :button_type1,
                        button_type2 = :button_type2,
                        button_type3 = :button_type3,
                        cta_url = :cta_url,
                        button_text1_type = :button_text1,
                        button_text2_type = :button_text2,
                        button_text3_type = :button_text3,
                        whatsapp = :whatsapp
                    WHERE id = :id AND shop = :shop AND aid = :aid
                ");

                $stmt->execute(array(
                    ':id' => $id,
                    ':shop' => $shop,
                    ':aid' => $aid,
                    ':order_name' => $order_name,
                    ':order_condition' => $order_condition,
                    ':timeinterval' => $timeinterval,
                    ':media_type' => $media_type,
                    ':media_url' => $media_url,
                    ':media_source' => $media_source,
                    ':button_type1' => $button_type1,
                    ':button_type2' => $button_type2,
                    ':button_type3' => $button_type3,
                    ':cta_url' => $cta_url_json,
                    ':button_text1' => $button_text1,
                    ':button_text2' => $button_text2,
                    ':button_text3' => $button_text3,
                    ':whatsapp' => $whatsapp_data
                ));
            } else {
                $stmt = $pdo->prepare("INSERT INTO $table (
                    aid, shop, order_name, order_condition, timeinterval, media_type, media_url, media_source,
                    button_type, button_type2, button_type3, cta_url, button_text1_type, 
                    button_text2_type, button_text3_type, whatsapp
                ) VALUES (
                    :aid, :shop, :order_name, :order_condition, :timeinterval, :media_type, :media_url, :media_source,
                    :button_type1, :button_type2, :button_type3, :cta_url, :button_text1,
                    :button_text2, :button_text3, :whatsapp
                )");

                $stmt->execute(array(
                    ':aid' => $aid,
                    ':shop' => $shop,
                    ':order_name' => $order_name,
                    ':order_condition' => $order_condition,
                    ':timeinterval' => $timeinterval,
                    ':media_type' => $media_type,
                    ':media_url' => $media_url,
                    ':media_source' => $media_source,
                    ':button_type1' => $button_type1,
                    ':button_type2' => $button_type2,
                    ':button_type3' => $button_type3,
                    ':cta_url' => $cta_url_json,
                    ':button_text1' => $button_text1,
                    ':button_text2' => $button_text2,
                    ':button_text3' => $button_text3,
                    ':whatsapp' => $whatsapp_data
                ));
            }

            send_json_response(array(
                'success' => true,
                'message' => 'WhatsApp template saved successfully.',
                'redirect_url' => $app_url . "/templates/time_period_based_message.php?shop=" . urlencode($shop),
                'media_url' => isset($media_url) ? $media_url : null
            ));
        }

        send_json_response(array(
            'success' => false,
            'message' => 'Unknown form submitted.'
        ));

    } catch (Exception $e) {
        send_json_response(array(
            'success' => false,
            'message' => 'Failed to save template: ' . $e->getMessage()
        ), 500);
    }
}

$stmt = $pdo->prepare("
    SELECT id, order_name, order_condition, timeinterval, sms_enabled, whatsapp_enabled 
    FROM $table 
    WHERE shop = :shop AND aid = 13
    ORDER BY id DESC
");
$stmt->execute(array(':shop' => $shop));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$orderChannelStatuses = array();
foreach ($rows as $row) {
    $orderChannelStatuses[$row['id']] = array(
        'sms' => (int) $row['sms_enabled'],
        'wa' => (int) $row['whatsapp_enabled']
    );
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Time Period Based</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600&display=swap"
        rel="stylesheet">
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet"
        href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/styles/style_promo_msgs.css">
    <meta name="shopify-api-key" content="<?php echo htmlspecialchars($api_key, ENT_QUOTES, 'UTF-8'); ?>" />
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <style>
        .switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 22px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 22px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked+.slider {
            background-color: #3b82f6;
        }

        input:checked+.slider:before {
            transform: translateX(18px);
        }

        .toggle-cell {
            text-align: center;
            padding: 8px 4px !important;
        }

        .action-header {
            text-align: center;
            padding: 8px 10px !important;
        }

        .custom-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
        }

        .custom-table th,
        .custom-table td {
            padding: 10px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #e5e7eb;
        }

        .custom-table th:nth-child(1),
        .custom-table td:nth-child(1) {
            width: 20%;
        }

        .custom-table th:nth-child(2),
        .custom-table td:nth-child(2) {
            width: 15%;
        }

        .custom-table th:nth-child(3),
        .custom-table td:nth-child(3) {
            width: 18%;
        }

        .custom-table th:nth-child(4),
        .custom-table td:nth-child(4) {
            width: 7%;
            padding: 8px 4px !important;
        }

        .custom-table th:nth-child(5),
        .custom-table td:nth-child(5) {
            width: 7%;
            padding: 8px 4px !important;
        }

        .custom-table th:nth-child(6),
        .custom-table td:nth-child(6) {
            width: 33%;
        }

        .action-cell {
            text-align: right;
            white-space: nowrap;
        }

        .action-cell button {
            margin-left: 6px;
            flex-shrink: 0;
        }

        .action-cell button:first-child {
            margin-left: 0;
        }

        .test-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background-color: #2563eb;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-right: 6px;
        }

        .test-btn .material-symbols-outlined {
            font-size: 13px;
        }

        .test-btn:hover {
            background-color: #1d4ed8;
            transform: translateY(-1px);
        }

        .link-btn {
            background: none;
            border: none;
            color: #3b82f6;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            padding: 5px 10px;
            border-radius: 6px;
            transition: all 0.2s ease;
        }

        .link-btn:hover {
            background-color: #eff6ff;
            color: #2563eb;
        }

        .link-btn.danger {
            color: #ef4444;
        }

        .link-btn.danger:hover {
            background-color: #fef2f2;
            color: #dc2626;
        }

        @media (max-width: 1200px) {
            .custom-table {
                font-size: 12px;
            }

            .custom-table th,
            .custom-table td {
                padding: 8px 6px;
            }

            .test-btn,
            .link-btn {
                padding: 4px 7px;
                font-size: 10px;
            }
        }
    </style>
</head>

<body>
    <div class="container-box">
        <div class="title-row">
            <a class="material-symbols-outlined"
                href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>?tab=promo&shop=<?php echo $shop; ?>">arrow_back</a>
            <div class="page-title">Promotional Message Based On Time Period</div>
        </div>
        <div class="header-row">
            <button class="submit-btn" onclick="openModal()">Add Time Period</button>
        </div>
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Condition</th>
                    <th>Time Interval</th>
                    <th class="action-header">SMS</th>
                    <th class="action-header">WhatsApp</th>
                    <th>Test</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($rows)): ?>
                    <?php foreach ($rows as $row): ?>
                        <tr id="row-<?= $row['id'] ?>">
                            <td><?= htmlspecialchars($row['order_name']) ?></td>
                            <td><?= htmlspecialchars($row['order_condition']) ?></td>
                            <td><?= htmlspecialchars($row['timeinterval']) ?></td>
                            <td class="toggle-cell">
                                <label class="switch">
                                    <input type="checkbox" class="toggle-switch sms-toggle" data-id="<?= $row['id'] ?>"
                                        data-channel="sms" <?php echo (isset($row['sms_enabled']) && $row['sms_enabled'] == '1') ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td class="toggle-cell">
                                <label class="switch">
                                    <input type="checkbox" class="toggle-switch whatsapp-toggle" data-id="<?= $row['id'] ?>"
                                        data-channel="whatsapp" <?php echo (isset($row['whatsapp_enabled']) && $row['whatsapp_enabled'] == '1') ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td>
                                <button type="button" class="test-btn"
                                    onclick="openTestModal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['order_name']) ?>')">
                                    <span class="material-symbols-outlined" style="font-size: 13px;">play_arrow</span>
                                    Test Trigger
                                </button>
                            </td>
                            <td class="action-cell">
                                <button class="link-btn" onclick="editRow(<?= $row['id'] ?>)">Edit</button>
                                <button class="link-btn danger" onclick="deleteRow(<?= $row['id'] ?>)">Remove</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align:center;">No data found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="modalOverlay" class="modal-overlay">
        <div class="modal-box">
            <button class="close-modal-btn" onclick="closeModal()">✕</button>
            <div class="left-panel">
                <div class="modal-tabs">
                    <button class="modal-tab-btn active" data-modal-tab="sms">SMS</button>
                    <button class="modal-tab-btn" data-modal-tab="whatsapp">WhatsApp</button>
                </div>

                <form method="POST" action="" enctype="multipart/form-data" id="smsForm">
                    <input type="hidden" name="id" id="edit_id">
                    <input type="hidden" name="aid" value="13">
                    <input type="hidden" name="sms_tab_submit" value="1">
                    <div class="modal-tab-content active" id="modal-sms">
                        <div class="condition-box">
                            <span>Name :</span>
                            <input type="text" name="order_name" id="sms_order_name">
                        </div>
                        <div class="condition-box">
                            <span>Condition :</span>
                            <select id="sms_timeCondition" name="order_condition">
                                <option value="days">Number of days</option>
                                <option value="hours">Number of hours</option>
                                <option value="weeks">Number of weeks</option>
                            </select>
                        </div>
                        <div class="condition-box">
                            <span id="sms_timeLabel">Number of Days :</span>
                            <input type="number" id="sms_timeInput" placeholder="Enter number of days"
                                name="timeinterval">
                        </div>
                        <div class="condition-box" style="flex-direction: column; align-items: flex-start;">
                            <!-- <span>SMS Content :</span> -->
                            <textarea placeholder="Enter SMS content" name="sms" id="sms_content" rows="4"
                                style="display:none;"></textarea>
                        </div>
                        <div class="condition-box">
                            <span>Template Name :</span>
                            <input type="text" name="sms_template_name" class="condition-input template-name-input"
                                placeholder="Enter template name" style="flex:1;" id="sms_template_name">
                        </div>
                        <div class="condition-box">
                            <span>Variables :</span>
                        </div>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <div class="condition-box">
                                <div style="display: flex; gap: 10px; flex: 1;">
                                    <input type="text" name="key_<?php echo $i; ?>" id="sms_key_<?php echo $i; ?>"
                                        placeholder="Key" style="flex: 1;">
                                    <span style="align-self: center;">:</span>
                                    <input type="text" name="var<?php echo $i; ?>" id="sms_var_<?php echo $i; ?>"
                                        placeholder="Value" style="flex: 1;">
                                </div>
                            </div>
                        <?php endfor; ?>
                        <div class="btn-group">
                            <button class="submit-btn" type="submit">Save Template</button>
                            <button type="button" class="cancel-btn" onclick="closeModal()">Cancel</button>
                        </div>
                    </div>
                </form>

                <form method="POST" action="" enctype="multipart/form-data" id="whatsappForm">
                    <input type="hidden" name="id" id="whatsapp_edit_id">
                    <input type="hidden" name="aid" value="13">
                    <input type="hidden" name="whatsapp_tab_submit" value="1">
                    <div class="modal-tab-content" id="modal-whatsapp">
                        <div class="condition-box">
                            <span>Name :</span>
                            <input type="text" name="order_name" id="whatsapp_order_name">
                        </div>
                        <div class="condition-box">
                            <span>Condition :</span>
                            <select id="whatsapp_timeCondition" name="order_condition">
                                <option value="days">Number of days</option>
                                <option value="hours">Number of hours</option>
                                <option value="weeks">Number of weeks</option>
                            </select>
                        </div>
                        <div class="condition-box">
                            <span id="whatsapp_timeLabel">Number of Days :</span>
                            <input type="number" id="whatsapp_timeInput" placeholder="Enter number of days"
                                name="timeinterval">
                        </div>
                        <div class="condition-box">
                            <span>Select Media Type :</span>
                            <select class="condition-select" id="mediaType" name="media_type">
                                <option value="image">Image</option>
                                <option value="video">Video</option>
                                <option value="text">Text</option>
                                <option value="pdf">Pdf</option>
                            </select>
                        </div>
                        <div class="condition-box" id="mediaSourceBox">
                            <span>Media Source :</span>
                            <label><input type="radio" name="media_source_type" value="url" checked> URL</label>
                            <label><input type="radio" name="media_source_type" value="file"> Upload</label>
                            <label id="dynamicImageOption" style="display:none;">
                                <input type="radio" name="media_source_type" value="dynamic"> Dynamic Image URL
                            </label>
                        </div>
                        <div class="condition-box" id="mediaUrlBox">
                            <input type="text" name="media_url" class="condition-input" placeholder="Enter media url"
                                style="flex:1;" id="media_url_input">
                        </div>
                        <div class="condition-box" id="mediaFileBox" style="display:none;">
                            <input type="file" name="media_file" class="condition-input">
                        </div>
                        <div class="condition-box" id="mediaFileUrlBox" style="display:none;">
                            <span>Generated Media URL :</span>
                            <input type="text" name="generated_media_url" class="condition-input"
                                placeholder="Media URL will appear here after upload"
                                style="flex:1; background-color: #f5f5f5;" readonly value="">
                        </div>
                        <div id="buttons-container"></div>
                        <div style="margin-bottom: 15px;">
                            <button type="button" class="add-button-btn" onclick="addButton()">
                                <span style="font-size: 14px;">+</span> Add Button
                            </button>
                        </div>
                        <div class="condition-box">
                            <span>Template Name :</span>
                            <input type="text" name="whatsapp_template_name" class="condition-input"
                                placeholder="Enter template name" style="flex:1;" id="whatsapp_template_name">
                        </div>
                        <div class="variables-section">
                            <div class="sub-section">
                                <div class="sub-section-title">
                                    <button type="button" class="add-btn" onclick="addVariableField('headers')">
                                        <span style="font-size: 14px;">Headers +</span>
                                    </button>
                                </div>
                                <div id="headers-container"></div>
                            </div>
                            <div class="sub-section">
                                <div class="sub-section-title">
                                    <button type="button" class="add-btn" onclick="addVariableField('body')">
                                        <span style="font-size: 14px;">Body +</span>
                                    </button>
                                </div>
                                <div id="body-container"></div>
                            </div>
                        </div>
                        <div class="btn-group">
                            <button class="submit-btn" type="submit">Save Template</button>
                            <button type="button" class="cancel-btn" onclick="closeModal()">Cancel</button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="right-panel">
                <div class="variable-box">
                    <h4>Liquid Variables</h4>
                    <p>You can use liquid variables to output dynamic values in your templates.</p>
                    <div class="variable-list">
                        <div class="variable-item">{{ order_name }}</div>
                        <div class="variable-item">{{ order_total_price }}</div>
                        <div class="variable-item">{{ customer_email_id }}</div>
                        <div class="variable-item">{{ country_code }}</div>
                        <div class="variable-item">{{ customer_phone }}</div>
                        <div class="variable-item">{{ order_created_at }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- <div id="testModal" class="test-modal-overlay">
        <div class="test-modal-box">
            <div class="test-modal-header">
                <h3>Send Test Message</h3>
                <button class="test-modal-close" onclick="closeTestModal()">✕</button>
            </div>
            <div class="test-modal-body">
                <div class="test-form-group">
                    <label>Country Code</label>
                    <select id="test_country_code" class="test-select">
                        <option value="+91">India (+91)</option>
                        <option value="+1">USA (+1)</option>
                        <option value="+44">UK (+44)</option>
                        <option value="+61">Australia (+61)</option>
                        <option value="+86">China (+86)</option>
                        <option value="+81">Japan (+81)</option>
                        <option value="+49">Germany (+49)</option>
                        <option value="+33">France (+33)</option>
                        <option value="+1">Canada (+1)</option>
                        <option value="+55">Brazil (+55)</option>
                        <option value="+7">Russia (+7)</option>
                        <option value="+82">South Korea (+82)</option>
                        <option value="+39">Italy (+39)</option>
                        <option value="+34">Spain (+34)</option>
                        <option value="+52">Mexico (+52)</option>
                        <option value="+31">Netherlands (+31)</option>
                        <option value="+46">Sweden (+46)</option>
                    </select>
                </div>
                <div class="test-form-group">
                    <label>Phone Number</label>
                    <input type="tel" id="test_phone" class="test-input" style="width: 90%;"
                        placeholder="Enter phone number (e.g., 9876543210)">
                    <small class="test-hint">Enter number without country code</small>
                </div>
            </div>
            <div class="test-modal-footer">
                <button class="test-cancel-btn" onclick="closeTestModal()">Cancel</button>
                <button class="test-submit-btn" onclick="sendTestFromModal()">Send Test</button>
            </div>
        </div>
    </div> -->
    <div id="testModal" class="test-modal-overlay">
        <div class="test-modal-box">
            <div class="test-modal-header">
                <h3>Send Test Message</h3>
                <button class="test-modal-close" onclick="closeTestModal()">✕</button>
            </div>
            <div class="test-modal-body">
                <div class="test-form-group" style="position: relative;">
                    <label>Select Country Code :</label>
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
                <div class="test-form-group">
                    <label>Phone Number</label>
                    <input type="tel" id="test_phone" class="test-input" style="width: 90%;"
                        placeholder="Enter phone number (e.g., 9876543210)">
                    <small class="test-hint">Enter number without country code</small>
                </div>
            </div>
            <div class="test-modal-footer">
                <button class="test-cancel-btn" onclick="closeTestModal()">Cancel</button>
                <button class="test-submit-btn" onclick="sendTestFromModal()">Send Test</button>
            </div>
        </div>
    </div>
    <div id="deleteModal" class="dmodal-overlay">
        <div class="dmodal-box">
            <h3>Confirm Delete</h3>
            <p>Are you sure you want to delete this row?</p>
            <div class="dmodal-actions">
                <button class="dmodal-delete" onclick="confirmDelete()">Yes, Delete</button>
                <button class="dmodal-cancel" onclick="closeDeleteModal()">Cancel</button>
            </div>
        </div>
    </div>
    <script>
        let buttonCount = 0;
        let deleteId = null;
        let currentEditId = null;
        var currentTestId = null;
        var currentTestName = null;
        var orderChannelStatuses = <?php echo json_encode($orderChannelStatuses); ?>;

        function getShopifySessionToken(callback) {
            if (window.shopify && typeof window.shopify.idToken === 'function') {
                window.shopify.idToken().then(function (token) {
                    callback(token || '');
                }).catch(function () {
                    callback('');
                });
                return;
            }
            callback('');
        }

        function attachAjaxFormSubmission(form) {
            if (!form) return;
            form.addEventListener('submit', function (e) {
                e.preventDefault();

                var submitButton = form.querySelector('button[type="submit"]');
                var originalText = submitButton ? submitButton.innerHTML : '';
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.innerHTML = '<span class="loading-spinner"></span> Saving...';
                }

                var formData = new FormData(form);
                formData.append('ajax', '1');

                var xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.href, true);
                xhr.onreadystatechange = function () {
                    if (xhr.readyState !== 4) return;

                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.innerHTML = originalText;
                    }

                    if (xhr.status === 200) {
                        var data = null;
                        try {
                            data = JSON.parse(xhr.responseText);
                        } catch (err) {
                            shopify.toast.show('Invalid server response. Please try again.', { isError: true, duration: 3000 });
                            return;
                        }

                        if (data && data.success) {
                            if (data.media_url) {
                                var generatedUrlInput = document.querySelector('input[name="generated_media_url"]');
                                if (generatedUrlInput) {
                                    generatedUrlInput.value = data.media_url;
                                    generatedUrlInput.style.color = '';
                                    generatedUrlInput.style.fontStyle = '';
                                    var mediaFileUrlBox = document.getElementById('mediaFileUrlBox');
                                    if (mediaFileUrlBox) {
                                        mediaFileUrlBox.style.display = 'flex';
                                    }
                                }
                            }
                            shopify.toast.show(data.message || 'Template saved successfully.', { duration: 3000 });
                            setTimeout(function () {
                                if (data.redirect_url) {
                                    window.location.href = data.redirect_url;
                                } else {
                                    location.reload();
                                }
                            }, 700);
                        } else {
                            shopify.toast.show((data && data.message) ? data.message : 'Failed to save template.', { isError: true, duration: 3000 });
                        }
                    } else {
                        shopify.toast.show('Failed to save template. Please try again.', { isError: true, duration: 3000 });
                    }
                };
                getShopifySessionToken(function (token) {
                    if (token) {
                        xhr.setRequestHeader('Authorization', 'Bearer ' + token);
                    }
                    xhr.send(formData);
                });
            });
        }

        // Toggle switch handler
        // document.addEventListener('DOMContentLoaded', function () {
        //     document.querySelectorAll('.toggle-switch').forEach(function (toggle) {
        //         toggle.addEventListener('change', function () {
        //             var recordId = this.getAttribute('data-id');
        //             var channel = this.getAttribute('data-channel');
        //             var enabled = this.checked ? 1 : 0;
        //             var toggleEl = this;

        //             var xhr = new XMLHttpRequest();
        //             xhr.open('POST', window.location.href, true);
        //             xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        //             xhr.onreadystatechange = function () {
        //                 if (xhr.readyState === 4) {
        //                     if (xhr.status === 200) {
        //                         var data = null;
        //                         try {
        //                             data = JSON.parse(xhr.responseText);
        //                         } catch (err) {
        //                             shopify.toast.show('Invalid server response', { isError: true, duration: 3000 });
        //                             toggleEl.checked = !toggleEl.checked;
        //                             return;
        //                         }

        //                         if (data && data.success) {
        //                             shopify.toast.show(data.message, { duration: 3000 });
        //                         } else {
        //                             shopify.toast.show((data && data.message) ? data.message : 'Update failed', { isError: true, duration: 3000 });
        //                             toggleEl.checked = !toggleEl.checked;
        //                         }
        //                     } else {
        //                         shopify.toast.show('Failed to update toggle', { isError: true, duration: 3000 });
        //                         toggleEl.checked = !toggleEl.checked;
        //                     }
        //                 }
        //             };

        //             var params = 'toggle_update=1&record_id=' + recordId + '&channel=' + channel + '&enabled=' + enabled;
        //             xhr.send(params);
        //         });
        //     });
        // });
        // Toggle switch handler
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.toggle-switch').forEach(function (toggle) {
                toggle.addEventListener('change', function (e) {
                    var recordId = this.getAttribute('data-id');
                    var channel = this.getAttribute('data-channel');
                    var enabled = this.checked ? 1 : 0;
                    var toggleEl = this;

                    // If trying to enable (turn ON), first check if template is configured
                    if (enabled == 1) {
                        // Fetch template data to check if configured
                        var xhrCheck = new XMLHttpRequest();
                        xhrCheck.open('POST', window.location.href, true);
                        xhrCheck.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhrCheck.onreadystatechange = function () {
                            if (xhrCheck.readyState === 4 && xhrCheck.status === 200) {
                                var checkData = null;
                                try {
                                    checkData = JSON.parse(xhrCheck.responseText);
                                } catch (err) {
                                    shopify.toast.show('Error checking template configuration', { isError: true, duration: 3000 });
                                    toggleEl.checked = false;
                                    return;
                                }

                                var isConfigured = false;
                                if (channel === 'sms') {
                                    if ((checkData.sms && checkData.sms.trim() !== '') || (checkData.template_name && checkData.template_name.trim() !== '')) {
                                        isConfigured = true;
                                    }
                                } else if (channel === 'whatsapp') {
                                    if (checkData.whatsapp && checkData.whatsapp.template_name && checkData.whatsapp.template_name.trim() !== '') {
                                        isConfigured = true;
                                    }
                                }

                                if (!isConfigured) {
                                    shopify.toast.show('Please configure ' + (channel === 'sms' ? 'SMS' : 'WhatsApp') + ' template first.', { isError: true, duration: 3000 });
                                    toggleEl.checked = false;
                                    return;
                                }

                                // Template is configured, proceed with toggle
                                proceedWithToggle();
                            }
                        };
                        xhrCheck.send('get_template_data=1&id=' + recordId);
                    } else {
                        // Turning OFF, proceed directly
                        proceedWithToggle();
                    }

                    function proceedWithToggle() {
                        var xhr = new XMLHttpRequest();
                        xhr.open('POST', window.location.href, true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr.onreadystatechange = function () {
                            if (xhr.readyState === 4) {
                                if (xhr.status === 200) {
                                    var data = null;
                                    try {
                                        data = JSON.parse(xhr.responseText);
                                    } catch (err) {
                                        shopify.toast.show('Invalid server response', { isError: true, duration: 3000 });
                                        toggleEl.checked = !toggleEl.checked;
                                        return;
                                    }

                                    if (data && data.success) {
                                        shopify.toast.show(data.message, { duration: 3000 });
                                        // Update the local status array
                                        if (orderChannelStatuses && orderChannelStatuses[recordId]) {
                                            if (channel === 'sms') {
                                                orderChannelStatuses[recordId].sms = enabled;
                                            } else {
                                                orderChannelStatuses[recordId].wa = enabled;
                                            }
                                        }
                                    } else {
                                        shopify.toast.show((data && data.message) ? data.message : 'Update failed', { isError: true, duration: 3000 });
                                        toggleEl.checked = !toggleEl.checked;
                                    }
                                } else {
                                    shopify.toast.show('Failed to update toggle', { isError: true, duration: 3000 });
                                    toggleEl.checked = !toggleEl.checked;
                                }
                            }
                        };
                        var params = 'toggle_update=1&record_id=' + recordId + '&channel=' + channel + '&enabled=' + enabled;
                        xhr.send(params);
                    }
                });
            });
        });

        const modalTabBtns = document.querySelectorAll('.modal-tab-btn');
        const modalTabContents = document.querySelectorAll('.modal-tab-content');

        if (modalTabBtns.length > 0) {
            modalTabBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    const tabId = btn.dataset.modalTab;
                    modalTabBtns.forEach(b => b.classList.remove('active'));
                    modalTabContents.forEach(c => c.classList.remove('active'));
                    btn.classList.add('active');
                    document.getElementById(`modal-${tabId}`).classList.add('active');
                });
            });
        }

        function openModal() {
            const modal = document.getElementById('modalOverlay');
            if (modal) modal.style.display = 'flex';
            resetForms();
        }

        function closeModal() {
            const modal = document.getElementById('modalOverlay');
            if (modal) modal.style.display = 'none';
            resetForms();
        }

        function resetForms() {
            const smsForm = document.getElementById('smsForm');
            const whatsappForm = document.getElementById('whatsappForm');
            if (smsForm) smsForm.reset();
            if (whatsappForm) whatsappForm.reset();

            const editId = document.getElementById('edit_id');
            const whatsappEditId = document.getElementById('whatsapp_edit_id');
            if (editId) editId.value = '';
            if (whatsappEditId) whatsappEditId.value = '';

            currentEditId = null;
            buttonCount = 0;

            const buttonsContainer = document.getElementById('buttons-container');
            const headersContainer = document.getElementById('headers-container');
            const bodyContainer = document.getElementById('body-container');

            if (buttonsContainer) buttonsContainer.innerHTML = '';
            if (headersContainer) headersContainer.innerHTML = '';
            if (bodyContainer) bodyContainer.innerHTML = '';

            addButton();
            addVariableField('headers');
            addVariableField('body');

            const smsTimeCondition = document.getElementById('sms_timeCondition');
            if (smsTimeCondition) smsTimeCondition.value = 'days';
            updateSMSTimeLabel();

            const whatsappTimeCondition = document.getElementById('whatsapp_timeCondition');
            if (whatsappTimeCondition) whatsappTimeCondition.value = 'days';
            updateWhatsAppTimeLabel();
        }

        function editRow(id) {
            currentEditId = id;
            fetch(`?edit_id=${id}&shop=<?php echo $shop; ?>`)
                .then(res => res.json())
                .then(data => {
                    openModal();

                    const smsOrderName = document.getElementById('sms_order_name');
                    const whatsappOrderName = document.getElementById('whatsapp_order_name');

                    if (smsOrderName) smsOrderName.value = data.order_name || '';
                    if (whatsappOrderName) whatsappOrderName.value = data.order_name || '';

                    const smsTimeInput = document.getElementById('sms_timeInput');
                    const whatsappTimeInput = document.getElementById('whatsapp_timeInput');
                    if (smsTimeInput) smsTimeInput.value = data.timeinterval || '';
                    if (whatsappTimeInput) whatsappTimeInput.value = data.timeinterval || '';

                    const smsContent = document.getElementById('sms_content');
                    if (smsContent) smsContent.value = data.sms || '';

                    const smsTemplateName = document.getElementById('sms_template_name');
                    if (smsTemplateName) smsTemplateName.value = data.template_name || '';

                    if (data.sms_variables) {
                        let vars = data.sms_variables;
                        let index = 1;
                        for (let key in vars) {
                            if (index <= 6) {
                                const keyInput = document.getElementById(`sms_key_${index}`);
                                const varInput = document.getElementById(`sms_var_${index}`);
                                if (keyInput) keyInput.value = key;
                                if (varInput) varInput.value = vars[key];
                                index++;
                            }
                        }
                    }

                    const mediaType = document.getElementById('mediaType');
                    const whatsappTemplateName = document.getElementById('whatsapp_template_name');
                    if (mediaType) mediaType.value = data.media_type || 'text';
                    if (whatsappTemplateName) whatsappTemplateName.value = (data.whatsapp && data.whatsapp.template_name) ? data.whatsapp.template_name : '';

                    const fileRadio = document.querySelector('input[name="media_source_type"][value="file"]');
                    const urlRadio = document.querySelector('input[name="media_source_type"][value="url"]');
                    const dynamicRadio = document.querySelector('input[name="media_source_type"][value="dynamic"]');
                    if (data.media_source === 'file') {
                        if (fileRadio) fileRadio.checked = true;
                    } else if (data.media_source === 'dynamic') {
                        if (dynamicRadio) dynamicRadio.checked = true;
                    } else {
                        if (urlRadio) urlRadio.checked = true;
                    }
                    const mediaUrlInput = document.getElementById('media_url_input');
                    if (mediaUrlInput) mediaUrlInput.value = data.media_url || '';
                    // Update generated URL box if file source
                    var generatedUrlInput = document.querySelector('input[name="generated_media_url"]');
                    if (generatedUrlInput && data.media_source === 'file' && data.media_url) {
                        generatedUrlInput.value = data.media_url;
                    }
                    toggleMediaFields();

                    const headersContainer = document.getElementById('headers-container');
                    const bodyContainer = document.getElementById('body-container');
                    if (headersContainer) headersContainer.innerHTML = '';
                    if (bodyContainer) bodyContainer.innerHTML = '';

                    if (data.whatsapp) {
                        let wp = data.whatsapp;
                        if (wp.variable_headers && wp.variable_headers.length > 0) {
                            wp.variable_headers.forEach((header) => {
                                addVariableField('headers', header);
                            });
                        } else {
                            addVariableField('headers');
                        }

                        if (wp.variable_body && wp.variable_body.length > 0) {
                            wp.variable_body.forEach((body) => {
                                addVariableField('body', body);
                            });
                        } else {
                            addVariableField('body');
                        }
                    } else {
                        addVariableField('headers');
                        addVariableField('body');
                    }

                    const buttonsContainer = document.getElementById('buttons-container');
                    if (buttonsContainer) buttonsContainer.innerHTML = '';
                    buttonCount = 0;

                    let buttons = [];
                    if (data.button_type || data.button_text1_type) {
                        buttons.push({
                            type: data.button_type || 'none',
                            text: data.button_text1_type || '',
                            url: (data.cta_url && data.cta_url.button1) ? data.cta_url.button1 : ''
                        });
                    }
                    if (data.button_type2 || data.button_text2_type) {
                        buttons.push({
                            type: data.button_type2 || 'none',
                            text: data.button_text2_type || '',
                            url: (data.cta_url && data.cta_url.button2) ? data.cta_url.button2 : ''
                        });
                    }
                    if (data.button_type3 || data.button_text3_type) {
                        buttons.push({
                            type: data.button_type3 || 'none',
                            text: data.button_text3_type || '',
                            url: (data.cta_url && data.cta_url.button3) ? data.cta_url.button3 : ''
                        });
                    }

                    if (buttons.length === 0) {
                        addButton();
                    } else {
                        buttons.forEach(btn => {
                            addButton(btn.type, btn.text, btn.url);
                        });
                    }

                    let condition = data.order_condition || 'days';
                    const smsTimeCondition = document.getElementById('sms_timeCondition');
                    if (smsTimeCondition) smsTimeCondition.value = condition;
                    updateSMSTimeLabel();
                    const whatsappTimeCondition = document.getElementById('whatsapp_timeCondition');
                    if (whatsappTimeCondition) whatsappTimeCondition.value = condition;
                    updateWhatsAppTimeLabel();

                    const editId = document.getElementById('edit_id');
                    const whatsappEditId = document.getElementById('whatsapp_edit_id');
                    if (editId) editId.value = data.id;
                    if (whatsappEditId) whatsappEditId.value = data.id;
                })
                .catch(err => console.error("Fetch error:", err));
        }

        function addButton(type = 'none', text = '', url = '') {
            if (buttonCount >= 3) {
                shopify.toast.show('Maximum 3 buttons allowed', { isError: true, duration: 3000 });
                return;
            }

            buttonCount++;
            var container = document.getElementById('buttons-container');
            if (!container) return;

            var newButtonGroup = document.createElement('div');
            newButtonGroup.className = 'button-group';
            newButtonGroup.id = 'button-group-' + buttonCount;
            newButtonGroup.innerHTML = '<div class="button-header">' +
                '<span class="button-title">Button ' + buttonCount + '</span>' +
                (buttonCount > 1 ? '<button type="button" class="remove-button-btn" onclick="removeButton(' + buttonCount + ')">' +
                    '<span><svg xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" width="20" height="20" viewBox="0 0 30 30">' +
                    '<path d="M 14.984375 2.4863281 A 1.0001 1.0001 0 0 0 14 3.5 L 14 4 L 8.5 4 A 1.0001 1.0001 0 0 0 7.4863281 5 L 6 5 A 1.0001 1.0001 0 1 0 6 7 L 24 7 A 1.0001 1.0001 0 1 0 24 5 L 22.513672 5 A 1.0001 1.0001 0 0 0 21.5 4 L 16 4 L 16 3.5 A 1.0001 1.0001 0 0 0 14.984375 2.4863281 z M 6 9 L 7.7929688 24.234375 C 7.9109687 25.241375 8.7633438 26 9.7773438 26 L 20.222656 26 C 21.236656 26 22.088031 25.241375 22.207031 24.234375 L 24 9 L 6 9 z"></path>' +
                    '</svg></span></button>' : '') +
                '</div>' +
                '<div class="button-row">' +
                '<select name="button_type' + buttonCount + '" class="button-type" data-button="' + buttonCount + '" onchange="toggleUrlField(' + buttonCount + ')">' +
                '<option value="none"' + (type === 'none' ? ' selected' : '') + '>None</option>' +
                '<option value="cta"' + (type === 'cta' ? ' selected' : '') + '>CTA</option>' +
                '<option value="quick"' + (type === 'quick' ? ' selected' : '') + '>Quick Reply</option>' +
                '</select>' +
                '<input type="text" name="button_text' + buttonCount + '" placeholder="Button Text" value="' + text.replace(/"/g, '&quot;') + '">' +
                '</div>' +
                '<div class="url-field" id="url-field-' + buttonCount + '" ' + (type === 'cta' ? 'style="display:flex;"' : '') + '>' +
                '<input type="text" name="button_url' + buttonCount + '" placeholder="Enter URL (e.g., https://example.com)" value="' + url.replace(/"/g, '&quot;') + '">' +
                '</div>';
            container.appendChild(newButtonGroup);
        }

        function removeButton(buttonNumber) {
            var buttonGroup = document.getElementById('button-group-' + buttonNumber);
            if (buttonGroup) {
                buttonGroup.remove();
                buttonCount--;
            }
        }

        function toggleUrlField(buttonNumber) {
            var select = document.querySelector('select[name="button_type' + buttonNumber + '"]');
            var urlField = document.getElementById('url-field-' + buttonNumber);
            if (select && urlField) {
                if (select.value === 'cta') {
                    urlField.style.display = 'flex';
                } else {
                    urlField.style.display = 'none';
                }
            }
        }

        function addVariableField(type, value = '') {
            var container = type === 'headers' ? document.getElementById('headers-container') : document.getElementById('body-container');
            if (!container) return;

            var fieldName = type === 'headers' ? 'variable_headers[]' : 'variable_body[]';
            var placeholder = type === 'headers' ? 'Enter header variable (e.g., {{ customer_fname }})' : 'Enter body variable (e.g., {{ product_name }})';

            var newRow = document.createElement('div');
            newRow.className = 'variable-row';
            newRow.innerHTML = '<input type="text" name="' + fieldName + '" placeholder="' + placeholder + '" value="' + value.replace(/"/g, '&quot;') + '">' +
                '<button type="button" class="remove-btn" onclick="removeVariableField(this, \'' + type + '\')">' +
                '<span><svg xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" width="20" height="20" viewBox="0 0 30 30">' +
                '<path d="M 14.984375 2.4863281 A 1.0001 1.0001 0 0 0 14 3.5 L 14 4 L 8.5 4 A 1.0001 1.0001 0 0 0 7.4863281 5 L 6 5 A 1.0001 1.0001 0 1 0 6 7 L 24 7 A 1.0001 1.0001 0 1 0 24 5 L 22.513672 5 A 1.0001 1.0001 0 0 0 21.5 4 L 16 4 L 16 3.5 A 1.0001 1.0001 0 0 0 14.984375 2.4863281 z M 6 9 L 7.7929688 24.234375 C 7.9109687 25.241375 8.7633438 26 9.7773438 26 L 20.222656 26 C 21.236656 26 22.088031 25.241375 22.207031 24.234375 L 24 9 L 6 9 z"></path>' +
                '</svg></span></button>';
            container.appendChild(newRow);
        }

        function removeVariableField(button, type) {
            var row = button.parentNode;
            var container = type === 'headers' ? document.getElementById('headers-container') : document.getElementById('body-container');
            if (container && container.children.length > 1) {
                row.remove();
            } else {
                var input = row.querySelector('input');
                if (input) {
                    input.value = '';
                }
            }
        }

        function deleteRow(id) {
            deleteId = id;
            const deleteModal = document.getElementById('deleteModal');
            if (deleteModal) deleteModal.style.display = 'flex';
        }

        function closeDeleteModal() {
            const deleteModal = document.getElementById('deleteModal');
            if (deleteModal) deleteModal.style.display = 'none';
        }

        function confirmDelete() {
            if (!deleteId) return;
            fetch(`?delete_id=${deleteId}&shop=<?php echo $shop; ?>`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'deleted') {
                        const row = document.getElementById(`row-${deleteId}`);
                        if (row) row.remove();
                        closeDeleteModal();
                        shopify.toast.show('Row deleted successfully', { duration: 3000 });
                    } else {
                        shopify.toast.show('Delete failed', { isError: true, duration: 3000 });
                    }
                })
                .catch(err => {
                    console.error("Delete error:", err);
                    shopify.toast.show('Delete failed', { isError: true, duration: 3000 });
                });
        }

        const radioButtons = document.querySelectorAll('input[name="media_source_type"]');
        const urlBox = document.getElementById('mediaUrlBox');
        const fileBox = document.getElementById('mediaFileBox');
        const mediaType = document.getElementById('mediaType');
        const mediaSourceSection = document.getElementById('mediaSourceBox');
        const dynamicOption = document.getElementById('dynamicImageOption');

        if (radioButtons.length > 0) {
            radioButtons.forEach(radio => {
                radio.addEventListener('change', toggleMediaFields);
            });
        }

        function toggleMediaFields() {
            var selected = null;
            for (var i = 0; i < radioButtons.length; i++) {
                if (radioButtons[i].checked) {
                    selected = radioButtons[i];
                    break;
                }
            }
            if (!selected && mediaType.value !== 'text') {
                for (var i = 0; i < radioButtons.length; i++) {
                    if (radioButtons[i].value === 'url') {
                        radioButtons[i].checked = true;
                        selected = radioButtons[i];
                        break;
                    }
                }
            }
            if (!selected) {
                if (mediaType.value === 'text') {
                    mediaSourceSection.style.display = 'none';
                    urlBox.style.display = 'none';
                    fileBox.style.display = 'none';
                    if (dynamicOption) dynamicOption.style.display = 'none';
                }
                return;
            }

            if (mediaType.value === 'text') {
                mediaSourceSection.style.display = 'none';
                urlBox.style.display = 'none';
                fileBox.style.display = 'none';
                if (dynamicOption) dynamicOption.style.display = 'none';
                return;
            }

            mediaSourceSection.style.display = 'block';

            if (mediaType.value === 'image') {
                if (dynamicOption) dynamicOption.style.display = 'inline-flex';
            } else {
                if (dynamicOption) dynamicOption.style.display = 'none';
                if (selected.value === 'dynamic') {
                    for (var i = 0; i < radioButtons.length; i++) {
                        if (radioButtons[i].value === 'url') {
                            radioButtons[i].checked = true;
                            selected = radioButtons[i];
                            break;
                        }
                    }
                }
            }
            var mediaFileUrlBox = document.getElementById('mediaFileUrlBox');
            var generatedUrlInput = document.querySelector('input[name="generated_media_url"]');

            if (selected.value === 'url') {
                urlBox.style.display = 'flex';
                fileBox.style.display = 'none';
                if (mediaFileUrlBox) mediaFileUrlBox.style.display = 'none';
            }
            else if (selected.value === 'file') {
                urlBox.style.display = 'none';
                fileBox.style.display = 'flex';
                if (mediaFileUrlBox && generatedUrlInput && generatedUrlInput.value) {
                    mediaFileUrlBox.style.display = 'flex';
                } else if (mediaFileUrlBox) {
                    mediaFileUrlBox.style.display = 'flex';
                }
            }
            else if (selected.value === 'dynamic') {
                urlBox.style.display = 'none';
                fileBox.style.display = 'none';
                if (mediaFileUrlBox) mediaFileUrlBox.style.display = 'none';
            }
        }

        if (mediaType) {
            mediaType.addEventListener('change', toggleMediaFields);
            toggleMediaFields();
        }

        const smsTimeCondition = document.getElementById('sms_timeCondition');
        const smsTimeLabel = document.getElementById('sms_timeLabel');
        const smsTimeInput = document.getElementById('sms_timeInput');

        function updateSMSTimeLabel() {
            if (smsTimeCondition && smsTimeCondition.value === 'days') {
                if (smsTimeLabel) smsTimeLabel.textContent = 'Number of Days :';
                if (smsTimeInput) smsTimeInput.placeholder = 'Enter number of days';
            } else if (smsTimeCondition && smsTimeCondition.value === 'hours') {
                if (smsTimeLabel) smsTimeLabel.textContent = 'Number of Hours :';
                if (smsTimeInput) smsTimeInput.placeholder = 'Enter number of hours';
            } else if (smsTimeCondition && smsTimeCondition.value === 'weeks') {
                if (smsTimeLabel) smsTimeLabel.textContent = 'Number of Weeks :';
                if (smsTimeInput) smsTimeInput.placeholder = 'Enter number of weeks';
            }
        }

        if (smsTimeCondition) {
            smsTimeCondition.addEventListener('change', updateSMSTimeLabel);
            updateSMSTimeLabel();
        }

        const whatsappTimeCondition = document.getElementById('whatsapp_timeCondition');
        const whatsappTimeLabel = document.getElementById('whatsapp_timeLabel');
        const whatsappTimeInput = document.getElementById('whatsapp_timeInput');

        function updateWhatsAppTimeLabel() {
            if (whatsappTimeCondition && whatsappTimeCondition.value === 'days') {
                if (whatsappTimeLabel) whatsappTimeLabel.textContent = 'Number of Days :';
                if (whatsappTimeInput) whatsappTimeInput.placeholder = 'Enter number of days';
            } else if (whatsappTimeCondition && whatsappTimeCondition.value === 'hours') {
                if (whatsappTimeLabel) whatsappTimeLabel.textContent = 'Number of Hours :';
                if (whatsappTimeInput) whatsappTimeInput.placeholder = 'Enter number of hours';
            } else if (whatsappTimeCondition && whatsappTimeCondition.value === 'weeks') {
                if (whatsappTimeLabel) whatsappTimeLabel.textContent = 'Number of Weeks :';
                if (whatsappTimeInput) whatsappTimeInput.placeholder = 'Enter number of weeks';
            }
        }

        if (whatsappTimeCondition) {
            whatsappTimeCondition.addEventListener('change', updateWhatsAppTimeLabel);
            updateWhatsAppTimeLabel();
        }

        document.querySelectorAll('.variable-item').forEach(item => {
            item.addEventListener('click', function () {
                const text = this.innerText.trim();
                navigator.clipboard.writeText(text)
                    .then(() => {
                        shopify.toast.show("Variable copied to clipboard", { duration: 3000 });
                    })
                    .catch(err => console.error("Copy failed:", err));
            });
        });

        document.addEventListener('DOMContentLoaded', function () {
            resetForms();
            var forms = document.querySelectorAll('#smsForm, #whatsappForm');
            for (var fi = 0; fi < forms.length; fi++) {
                attachAjaxFormSubmission(forms[fi]);
            }
            // Add file input change event
            var fileInput = document.querySelector('input[name="media_file"]');
            if (fileInput) {
                fileInput.addEventListener('change', previewFileUrl);
            }
        });
        // function openTestModal(id, name) {
        //     currentTestId = id;
        //     currentTestName = name;
        //     const testModal = document.getElementById('testModal');
        //     if (testModal) {
        //         testModal.style.display = 'flex';
        //     }
        //     const testPhone = document.getElementById('test_phone');
        //     if (testPhone) {
        //         testPhone.value = '';
        //     }

        //     var select = document.getElementById('test_country_code');
        //     var searchInput = document.getElementById('country_search');
        //     if (select && searchInput) {
        //         var selectedOption = select.options[select.selectedIndex];
        //         if (selectedOption) {
        //             searchInput.value = selectedOption.text;
        //         }
        //         setTimeout(function () {
        //             searchInput.focus();
        //             searchInput.select();
        //         }, 100);
        //     }
        // }

        function openTestModal(id, name) {
            currentTestId = id;
            currentTestName = name;

            // Check if SMS or WhatsApp is enabled for this record
            var smsEnabled = false;
            var whatsappEnabled = false;

            if (orderChannelStatuses && orderChannelStatuses[id]) {
                smsEnabled = orderChannelStatuses[id].sms === 1;
                whatsappEnabled = orderChannelStatuses[id].wa === 1;
            }

            // If both are disabled, show error and don't open modal
            if (!smsEnabled && !whatsappEnabled) {
                shopify.toast.show("Please enable SMS or WhatsApp template first.", { isError: true, duration: 3000 });
                return;
            }

            const testModal = document.getElementById('testModal');
            if (testModal) {
                testModal.style.display = 'flex';
            }
            const testPhone = document.getElementById('test_phone');
            if (testPhone) {
                testPhone.value = '';
            }

            var select = document.getElementById('test_country_code');
            var searchInput = document.getElementById('country_search');
            if (select && searchInput) {
                var selectedOption = select.options[select.selectedIndex];
                if (selectedOption) {
                    searchInput.value = selectedOption.text;
                }
                setTimeout(function () {
                    searchInput.focus();
                    searchInput.select();
                }, 100);
            }
        }

        function closeTestModal() {
            const testModal = document.getElementById('testModal');
            if (testModal) {
                testModal.style.display = 'none';
            }
            currentTestId = null;
            currentTestName = null;

            var searchInput = document.getElementById('country_search');
            if (searchInput) {
                searchInput.value = '';
            }
            var select = document.getElementById('test_country_code');
            if (select) {
                var options = select.options;
                for (var i = 0; i < options.length; i++) {
                    options[i].style.display = '';
                }
            }
        }

        function filterCountries() {
            var searchInput = document.getElementById('country_search');
            var select = document.getElementById('test_country_code');
            if (!searchInput || !select) return;

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
            if (!select || !searchInput) return;

            var selectedOption = select.options[select.selectedIndex];
            if (selectedOption) {
                searchInput.value = selectedOption.text;
            }
        }

        // function sendTestFromModal() {
        //     var countryCode = document.getElementById('test_country_code').value;
        //     var phone = document.getElementById('test_phone').value;

        //     if (!phone) {
        //         shopify.toast.show("Please enter phone number", { isError: true, duration: 3000 });
        //         return;
        //     }

        //     var phoneRegex = /^\d{5,15}$/;
        //     if (!phoneRegex.test(phone)) {
        //         shopify.toast.show("Please enter a valid phone number (5-15 digits)", { isError: true, duration: 3000 });
        //         return;
        //     }

        //     var sendButton = document.querySelector('.test-submit-btn');
        //     var originalText = sendButton.innerHTML;
        //     sendButton.disabled = true;
        //     sendButton.innerHTML = '<span class="loading-spinner"></span> Sending...';

        //     fetch('/notifycsapp/test_trigger/test_time_period_message.php', {
        //         method: 'POST',
        //         headers: {
        //             'Content-Type': 'application/json'
        //         },
        //         body: JSON.stringify({
        //             country_code: countryCode,
        //             phone: phone,
        //             shop: "<?php echo $shop; ?>",
        //             record_id: currentTestId
        //         })
        //     })
        //         .then(function (res) { return res.json(); })
        //         .then(function (data) {
        //             if (data.success) {
        //                 closeTestModal();
        //                 shopify.toast.show('✓ Test message sent successfully! Check the status in App Logs.', { duration: 3000 });
        //             } else {
        //                 shopify.toast.show(data.message || "Error sending test message", { isError: true, duration: 3000 });
        //             }
        //             sendButton.disabled = false;
        //             sendButton.innerHTML = originalText;
        //         })
        //         .catch(function (err) {
        //             console.error(err);
        //             shopify.toast.show("Network error. Please try again.", { isError: true, duration: 3000 });
        //             sendButton.disabled = false;
        //             sendButton.innerHTML = originalText;
        //         });
        // }

        function sendTestFromModal() {
            var countryCode = document.getElementById('test_country_code').value;
            var phone = document.getElementById('test_phone').value;

            if (!phone) {
                shopify.toast.show("Please enter phone number", { isError: true, duration: 3000 });
                return;
            }

            var phoneRegex = /^\d{5,15}$/;
            if (!phoneRegex.test(phone)) {
                shopify.toast.show("Please enter a valid phone number (5-15 digits)", { isError: true, duration: 3000 });
                return;
            }

            // Verify template is still enabled
            var smsEnabled = false;
            var whatsappEnabled = false;

            if (orderChannelStatuses && orderChannelStatuses[currentTestId]) {
                smsEnabled = orderChannelStatuses[currentTestId].sms === 1;
                whatsappEnabled = orderChannelStatuses[currentTestId].wa === 1;
            }

            if (!smsEnabled && !whatsappEnabled) {
                shopify.toast.show("Please enable SMS or WhatsApp template first.", { isError: true, duration: 3000 });
                return;
            }

            var sendButton = document.querySelector('.test-submit-btn');
            var originalText = sendButton.innerHTML;
            sendButton.disabled = true;
            sendButton.innerHTML = '<span class="loading-spinner"></span> Sending...';

            fetch('/notifycsapp/test_trigger/test_time_period_message.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    country_code: countryCode,
                    phone: phone,
                    shop: "<?php echo $shop; ?>",
                    record_id: currentTestId
                })
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success) {
                        closeTestModal();
                        shopify.toast.show('✓ Test message sent successfully! Check the status in App Logs.', { duration: 3000 });
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
        }

        window.onclick = function (event) {
            var modal = document.getElementById('testModal');
            if (event.target == modal) {
                closeTestModal();
            }
        }
        function previewFileUrl() {
            var fileInput = document.querySelector('input[name="media_file"]');
            var generatedUrlInput = document.querySelector('input[name="generated_media_url"]');
            var mediaFileUrlBox = document.getElementById('mediaFileUrlBox');

            if (fileInput && fileInput.files && fileInput.files[0]) {
                var fileName = fileInput.files[0].name;
                var timestamp = Math.floor(Date.now() / 1000);
                var fileExtension = fileName.split('.').pop().toLowerCase();
                var baseName = fileName.substring(0, fileName.lastIndexOf('.')) || fileName;
                var cleanName = baseName.replace(/[^a-zA-Z0-9._-]/g, '');
                var predictedFileName = timestamp + '_' + cleanName + '.' + fileExtension;

                var predictedUrl = '<?php echo rtrim($app_url, '/'); ?>/templates/uploads/' + predictedFileName;

                if (generatedUrlInput) {
                    generatedUrlInput.value = 'Will be: ' + predictedUrl;
                    generatedUrlInput.style.color = '#666';
                    generatedUrlInput.style.fontStyle = 'italic';
                }
                if (mediaFileUrlBox) {
                    mediaFileUrlBox.style.display = 'flex';
                }
            }
        }
    </script>
</body>

</html>