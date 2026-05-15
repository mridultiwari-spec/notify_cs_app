<?php
session_start();
require_once dirname(__FILE__) . '/../config/db.php';
require_once dirname(__FILE__) . '/../app_config.php';
require_once dirname(__FILE__) . '/../config/session_token_auth.php';

$shop = '';
if (isset($_SESSION['shop'])) {
    $shop = $_SESSION['shop'];
} elseif (isset($_POST['shop'])) {
    $shop = $_POST['shop'];
} elseif (isset($_GET['shop'])) {
    $shop = $_GET['shop'];
} else {
    $shop = '';
}

if (!$shop) {
    die("Shop not found");
}
session_write_close();
$pdo = getDatabaseConnection();
$tables = $prefix . "shopify_sms_notification_app";
$stmt = $pdo->prepare("SELECT shop, session_access_token AS oauth_token FROM $tables WHERE shop = :shop");
$stmt->execute(array(':shop' => $shop));
$data = $stmt->fetch();

if (!$data) {
    die("Shop not installed");
}

function getExistingData($pdo, $table, $aid, $shop)
{
    $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE aid = :aid AND shop = :shop LIMIT 1");
    $stmt->execute(array(':aid' => $aid, ':shop' => $shop));
    return $stmt->fetch();
}

$aid = isset($_GET['aid']) ? (int) $_GET['aid'] : (isset($_POST['aid']) ? (int) $_POST['aid'] : 7);
$table = $prefix . "shopify_sms_notification_App_Email_Notification";
$existingData = getExistingData($pdo, $table, $aid, $shop);
$isEditMode = ($existingData !== false);

function send_json_response($payload)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    $aid = isset($_POST['aid']) ? (int) $_POST['aid'] : 0;
    if ($aid <= 0) {
        die("Invalid aid");
    }

    try {
        $table = $prefix . "shopify_sms_notification_App_Email_Notification";

        if (isset($_POST['sms'])) {
            $sms = trim($_POST['sms']);
            $template_name_sms = isset($_POST['sms_template_name']) ? trim($_POST['sms_template_name']) : '';

            // if (empty($sms)) {
            //     die("SMS empty");
            // }

            $variables = array();
            for ($i = 1; $i <= 6; $i++) {
                $key = isset($_POST["key_$i"]) ? trim($_POST["key_$i"]) : '';
                $value = isset($_POST["var$i"]) ? trim($_POST["var$i"]) : '';

                if (!empty($key) && !empty($value)) {
                    $variables[$key] = $value;
                }
            }
            $sms_variables = json_encode($variables);

            $checkStmt = $pdo->prepare("SELECT id FROM `$table` WHERE aid = :aid AND shop = :shop");
            $checkStmt->execute(array(':aid' => $aid, ':shop' => $shop));
            $exists = $checkStmt->fetch();

            if ($exists) {
                $stmt = $pdo->prepare("
                    UPDATE `$table` 
                    SET sms = :sms, template_name = :template_name, sms_variables = :sms_variables
                    WHERE aid = :aid AND shop = :shop
                ");
                $stmt->execute(array(
                    ':aid' => $aid,
                    ':sms' => $sms,
                    ':shop' => $shop,
                    ':template_name' => $template_name_sms,
                    ':sms_variables' => $sms_variables
                ));
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO `$table` (aid, sms, shop, template_name, sms_variables)
                    VALUES (:aid, :sms, :shop, :template_name, :sms_variables)
                ");
                $stmt->execute(array(
                    ':aid' => $aid,
                    ':sms' => $sms,
                    ':shop' => $shop,
                    ':template_name' => $template_name_sms,
                    ':sms_variables' => $sms_variables
                ));
            }

            send_json_response(array(
                'success' => true,
                'message' => 'Template saved successfully.',
                'redirect_url' => $app_url . "/index.php?tab=shipping&shop=" . urlencode($shop)
            ));
        }

        if (isset($_POST['media_type'])) {
            $media_type = isset($_POST['media_type']) ? $_POST['media_type'] : 'text';
            $media_source_type = isset($_POST['media_source_type']) ? $_POST['media_source_type'] : 'url';
            $media_url = null;
            $media_source = null;

            // if ($media_type !== 'text') {
            //     if ($media_source_type === 'url') {
            //         if (!empty($_POST['media_url'])) {
            //             $media_source = 'input';
            //             $media_url = trim($_POST['media_url']);
            //         }
            //     } elseif ($media_source_type === 'file') {
            //         if (isset($_FILES['media_file']) && $_FILES['media_file']['error'] === 0) {
            //             $uploadDir = "uploads/";
            //             if (!is_dir($uploadDir)) {
            //                 mkdir($uploadDir, 0777, true);
            //             }
            //             $fileName = time() . "_" . preg_replace(
            //                 "/[^a-zA-Z0-9._-]/",
            //                 "",
            //                 $_FILES["media_file"]["name"]
            //             );
            //             $targetFile = $uploadDir . $fileName;
            //             if (move_uploaded_file($_FILES["media_file"]["tmp_name"], $targetFile)) {
            //                 $media_source = 'file';
            //                 $media_url = $fileName;
            //             }
            //         }
            //     }
            // }

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

                        $targetFile = $uploadDir . $fileName;
                        if (move_uploaded_file($_FILES["media_file"]["tmp_name"], $targetFile)) {
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

            $template_name = isset($_POST['whatsapp']) ? $_POST['whatsapp'] : '';
            $variable_headers = isset($_POST['variable_headers']) ? $_POST['variable_headers'] : array();
            $variable_body = isset($_POST['variable_body']) ? $_POST['variable_body'] : array();
            $variable_headers = array_values(array_filter($variable_headers));
            $variable_body = array_values(array_filter($variable_body));

            $whatsapp_data = json_encode(array(
                "template_name" => $template_name,
                "variable_headers" => $variable_headers,
                "variable_body" => $variable_body
            ));

            $checkStmt = $pdo->prepare("SELECT id FROM `$table` WHERE aid = :aid AND shop = :shop");
            $checkStmt->execute(array(':aid' => $aid, ':shop' => $shop));
            $exists = $checkStmt->fetch();

            if ($exists) {

                $stmt = $pdo->prepare("
                    UPDATE `$table` SET 
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
                    WHERE aid = :aid AND shop = :shop
                ");

                $stmt->execute(array(
                    ':aid' => $aid,
                    ':shop' => $shop,
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

                $stmt = $pdo->prepare("INSERT INTO `$table` (
                    aid, 
                    shop, 
                    media_type, 
                    media_url, 
                    media_source, 
                    button_type, 
                    button_type2, 
                    button_type3, 
                    cta_url, 
                    button_text1_type, 
                    button_text2_type, 
                    button_text3_type, 
                    whatsapp
                ) VALUES (
                    :aid, 
                    :shop, 
                    :media_type, 
                    :media_url, 
                    :media_source, 
                    :button_type1, 
                    :button_type2, 
                    :button_type3, 
                    :cta_url, 
                    :button_text1, 
                    :button_text2, 
                    :button_text3, 
                    :whatsapp
                )");

                $stmt->execute(array(
                    ':aid' => $aid,
                    ':shop' => $shop,
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
                'message' => 'Template saved successfully.',
                'redirect_url' => $app_url . "/index.php?tab=shipping&shop=" . urlencode($shop),
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
            'message' => 'Failed to save template. Please try again.'
        ));
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="shopify-api-key" content="<?php echo htmlspecialchars($api_key, ENT_QUOTES, 'UTF-8'); ?>">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600&display=swap"
        rel="stylesheet">
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&icon_names=arrow_back" />
    <link rel="stylesheet"
        href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/styles/style.css">
    <title>Shipping Confirmation</title>
</head>

<body>
    <div class="container-box">
        <div class="page-header">
            <div class="header-left">
                <a class="material-symbols-outlined"
                    href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>?tab=shipping&shop=<?php echo $shop; ?>">arrow_back</a>
                <div class="page-title">Shipping Confirmation</div>
            </div>
        </div>
        <div class="content-wrapper">
            <div class="left-panel">
                <div class="tabs">
                    <div class="tab-btn active" data-tab="sms">SMS</div>
                    <div class="tab-btn" data-tab="whatsapp">WhatsApp</div>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="shop"
                        value="<?php echo htmlspecialchars(isset($_GET['shop']) ? $_GET['shop'] : '', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="aid" value="<?php echo $aid; ?>">
                    <div class="tab-content active" id="sms">
                        <textarea id="smsBox" name="sms" placeholder="Enter SMS template..."
                            style="display:none;"><?php echo $isEditMode && isset($existingData['sms']) ? htmlspecialchars($existingData['sms'], ENT_QUOTES, 'UTF-8') : ''; ?></textarea></br>
                        <p></p>
                        <span>Template Name :</span>
                        <p></p>
                        <input type="text" name="sms_template_name" class="condition-input template-name-input"
                            placeholder="Enter template name" style="flex:1;"
                            value="<?php echo $isEditMode && isset($existingData['template_name']) ? htmlspecialchars($existingData['template_name'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                        </br>
                        <p></p>
                        <span>Variables : </span>
                        <p></p>
                        <?php
                        $smsVariables = array();
                        if ($isEditMode && isset($existingData['sms_variables'])) {
                            $smsVariables = json_decode($existingData['sms_variables'], true);
                            if (!is_array($smsVariables))
                                $smsVariables = array();
                        }

                        for ($i = 1; $i <= 6; $i++):
                            $keys = array_keys($smsVariables);
                            $values = array_values($smsVariables);
                            $currentKey = isset($keys[$i - 1]) ? $keys[$i - 1] : '';
                            $currentValue = isset($values[$i - 1]) ? $values[$i - 1] : '';
                            ?>
                            <div class="condition-box">
                                <div style="display: flex; gap: 10px; flex: 1;">
                                    <input type="text" name="key_<?php echo $i; ?>" id="sms_key_<?php echo $i; ?>"
                                        placeholder="Key" style="flex: 1;"
                                        value="<?php echo htmlspecialchars($currentKey, ENT_QUOTES, 'UTF-8'); ?>">
                                    <span style="align-self: center;">:</span>
                                    <input type="text" name="var<?php echo $i; ?>" id="sms_var_<?php echo $i; ?>"
                                        placeholder="Value" style="flex: 1;"
                                        value="<?php echo htmlspecialchars($currentValue, ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            </div>
                        <?php endfor; ?>
                        <div class="btn-group">
                            <button class="submit-btn"
                                type="submit"><?php echo $isEditMode ? 'Update Template' : 'Save Template'; ?></button>
                            <!-- <button type="button" class="cancel-btn" onclick="location.href='/index.php'">Cancel</button> -->
                        </div>
                    </div>
                </form>
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="shop"
                        value="<?php echo htmlspecialchars(isset($_GET['shop']) ? $_GET['shop'] : '', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="aid" value="<?php echo $aid; ?>">
                    <div class="tab-content" id="whatsapp">
                        <div class="condition-box">
                            <span>Select Media Type :</span>
                            <select class="condition-select" id="mediaType" name="media_type">
                                <option value="image" <?php echo ($isEditMode && isset($existingData['media_type']) && $existingData['media_type'] == 'image') ? 'selected' : ''; ?>>Image</option>
                                <option value="video" <?php echo ($isEditMode && isset($existingData['media_type']) && $existingData['media_type'] == 'video') ? 'selected' : ''; ?>>Video</option>
                                <option value="text" <?php echo ($isEditMode && isset($existingData['media_type']) && $existingData['media_type'] == 'text') ? 'selected' : ''; ?>>Text</option>
                                <option value="pdf" <?php echo ($isEditMode && isset($existingData['media_type']) && $existingData['media_type'] == 'pdf') ? 'selected' : ''; ?>>Pdf</option>
                            </select>
                        </div>
                        <div class="condition-box" id="mediaSourceBox">
                            <span>Media Source :</span>
                            <label>
                                <input type="radio" name="media_source_type" value="url" <?php echo ($isEditMode && isset($existingData['media_source']) && $existingData['media_source'] == 'input') ? 'checked' : ''; ?>> URL
                            </label>
                            <label>
                                <input type="radio" name="media_source_type" value="file" <?php echo ($isEditMode && isset($existingData['media_source']) && $existingData['media_source'] == 'file') ? 'checked' : ''; ?>> Upload
                            </label>
                            <label id="dynamicImageOption" style="display:none;">
                                <input type="radio" name="media_source_type" value="dynamic"> Dynamic Image URL
                            </label>
                        </div>
                        <div class="condition-box" id="mediaUrlBox">
                            <input type="text" name="media_url" class="condition-input" placeholder="Enter media url"
                                style="flex:1;"
                                value="<?php echo $isEditMode && isset($existingData['media_url']) ? htmlspecialchars($existingData['media_url'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                        </div>
                        <div class="condition-box" id="mediaFileBox" style="display:none;">
                            <input type="file" name="media_file" class="condition-input">
                        </div>
                        <div class="condition-box" id="mediaFileUrlBox" style="display:none;">
                            <span>Generated Media URL :</span>
                            <input type="text" name="generated_media_url" class="condition-input"
                                placeholder="Media URL will appear here after upload"
                                style="flex:1; background-color: #f5f5f5;" readonly
                                value="<?php echo ($isEditMode && isset($existingData['media_source']) && $existingData['media_source'] == 'file' && !empty($existingData['media_url'])) ? htmlspecialchars($existingData['media_url'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                        </div>
                        <div id="buttons-container">
                            <?php

                            $ctaUrls = array();
                            if ($isEditMode && isset($existingData['cta_url']) && $existingData['cta_url']) {
                                $ctaUrls = json_decode($existingData['cta_url'], true);
                                if (!is_array($ctaUrls))
                                    $ctaUrls = array();
                            }


                            for ($btn = 1; $btn <= 3; $btn++) {
                                $buttonType = '';
                                $buttonText = '';
                                $buttonUrl = '';

                                if ($isEditMode) {
                                    if ($btn == 1) {
                                        $buttonType = isset($existingData['button_type']) ? $existingData['button_type'] : '';
                                        $buttonText = isset($existingData['button_text1_type']) ? $existingData['button_text1_type'] : '';
                                        $buttonUrl = isset($ctaUrls['button1']) ? $ctaUrls['button1'] : '';
                                    } elseif ($btn == 2) {
                                        $buttonType = isset($existingData['button_type2']) ? $existingData['button_type2'] : '';
                                        $buttonText = isset($existingData['button_text2_type']) ? $existingData['button_text2_type'] : '';
                                        $buttonUrl = isset($ctaUrls['button2']) ? $ctaUrls['button2'] : '';
                                    } elseif ($btn == 3) {
                                        $buttonType = isset($existingData['button_type3']) ? $existingData['button_type3'] : '';
                                        $buttonText = isset($existingData['button_text3_type']) ? $existingData['button_text3_type'] : '';
                                        $buttonUrl = isset($ctaUrls['button3']) ? $ctaUrls['button3'] : '';
                                    }
                                }

                                if ($isEditMode && ($buttonType || $buttonText)) {
                                    ?>
                                    <div class="button-group" id="button-group-<?php echo $btn; ?>">
                                        <div class="button-header">
                                            <span class="button-title">Button <?php echo $btn; ?></span>
                                            <?php if ($btn > 1): ?>
                                                <button type="button" class="remove-button-btn"
                                                    onclick="removeButton(<?php echo $btn; ?>)">
                                                    <span><svg xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" width="20"
                                                            height="20" viewBox="0 0 30 30">
                                                            <path
                                                                d="M 14.984375 2.4863281 A 1.0001 1.0001 0 0 0 14 3.5 L 14 4 L 8.5 4 A 1.0001 1.0001 0 0 0 7.4863281 5 L 6 5 A 1.0001 1.0001 0 1 0 6 7 L 24 7 A 1.0001 1.0001 0 1 0 24 5 L 22.513672 5 A 1.0001 1.0001 0 0 0 21.5 4 L 16 4 L 16 3.5 A 1.0001 1.0001 0 0 0 14.984375 2.4863281 z M 6 9 L 7.7929688 24.234375 C 7.9109687 25.241375 8.7633438 26 9.7773438 26 L 20.222656 26 C 21.236656 26 22.088031 25.241375 22.207031 24.234375 L 24 9 L 6 9 z">
                                                            </path>
                                                        </svg></span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <div class="button-row">
                                            <select name="button_type<?php echo $btn; ?>" class="button-type"
                                                data-button="<?php echo $btn; ?>"
                                                onchange="toggleUrlField(<?php echo $btn; ?>)">
                                                <option value="none" <?php echo ($buttonType == 'none') ? 'selected' : ''; ?>>None
                                                </option>
                                                <option value="cta" <?php echo ($buttonType == 'cta') ? 'selected' : ''; ?>>CTA
                                                </option>
                                                <option value="quick" <?php echo ($buttonType == 'quick') ? 'selected' : ''; ?>>
                                                    Quick Reply</option>
                                            </select>
                                            <input type="text" name="button_text<?php echo $btn; ?>" placeholder="Button Text"
                                                value="<?php echo htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>
                                        <div class="url-field" id="url-field-<?php echo $btn; ?>" <?php echo ($buttonType == 'cta') ? 'style="display:flex;"' : ''; ?>>
                                            <input type="text" name="button_url<?php echo $btn; ?>"
                                                placeholder="Enter URL (e.g., https://example.com)"
                                                value="<?php echo htmlspecialchars($buttonUrl, ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>
                                    </div>
                                    <?php
                                }
                            }
                            ?>
                        </div>

                        <div style="margin-bottom: 15px;">
                            <button type="button" class="add-button-btn" id="addButtonBtn" onclick="addButton()">
                                <span style="font-size: 14px;">+</span> Add Button
                            </button>
                        </div>
                        <div class="condition-box">
                            <span>Template Name :</span>
                            <?php
                            $whatsappData = array();
                            if ($isEditMode && isset($existingData['whatsapp'])) {
                                $whatsappData = json_decode($existingData['whatsapp'], true);
                                if (!is_array($whatsappData))
                                    $whatsappData = array();
                            }
                            $whatsappTemplateName = isset($whatsappData['template_name']) ? $whatsappData['template_name'] : '';
                            ?>
                            <input type="text" name="whatsapp" class="condition-input" placeholder="Enter template name"
                                style="flex:1;"
                                value="<?php echo htmlspecialchars($whatsappTemplateName, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="variables-section">
                            <div class="variables-header">
                                <span>Variables</span>
                            </div>
                            <div class="sub-section">
                                <div class="sub-section-title">
                                    <button type="button" class="add-btn" onclick="addVariableField('headers')">
                                        <span style="font-size: 14px;">Headers +</span>
                                    </button>
                                </div>
                                <div id="headers-container">
                                    <?php
                                    $variableHeaders = isset($whatsappData['variable_headers']) ? $whatsappData['variable_headers'] : array();
                                    if (empty($variableHeaders))
                                        $variableHeaders = array('');

                                    foreach ($variableHeaders as $index => $header):
                                        ?>
                                        <div class="variable-row" data-id="header_<?php echo $index; ?>">
                                            <input type="text" name="variable_headers[]"
                                                placeholder="Enter header variable (e.g., {{ customer_fname }})"
                                                value="<?php echo htmlspecialchars($header, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php if ($index > 0): ?>
                                                <button type="button" class="remove-btn"
                                                    onclick="removeVariableField(this, 'headers')">
                                                    <span><svg xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" width="20"
                                                            height="20" viewBox="0 0 30 30">
                                                            <path
                                                                d="M 14.984375 2.4863281 A 1.0001 1.0001 0 0 0 14 3.5 L 14 4 L 8.5 4 A 1.0001 1.0001 0 0 0 7.4863281 5 L 6 5 A 1.0001 1.0001 0 1 0 6 7 L 24 7 A 1.0001 1.0001 0 1 0 24 5 L 22.513672 5 A 1.0001 1.0001 0 0 0 21.5 4 L 16 4 L 16 3.5 A 1.0001 1.0001 0 0 0 14.984375 2.4863281 z M 6 9 L 7.7929688 24.234375 C 7.9109687 25.241375 8.7633438 26 9.7773438 26 L 20.222656 26 C 21.236656 26 22.088031 25.241375 22.207031 24.234375 L 24 9 L 6 9 z">
                                                            </path>
                                                        </svg></span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="sub-section">
                                <div class="sub-section-title">
                                    <button type="button" class="add-btn" onclick="addVariableField('body')">
                                        <span style="font-size: 14px;">Body +</span>
                                    </button>
                                </div>
                                <div id="body-container">
                                    <?php
                                    $variableBody = isset($whatsappData['variable_body']) ? $whatsappData['variable_body'] : array();
                                    if (empty($variableBody))
                                        $variableBody = array('');

                                    foreach ($variableBody as $index => $body):
                                        ?>
                                        <div class="variable-row" data-id="body_<?php echo $index; ?>">
                                            <input type="text" name="variable_body[]"
                                                placeholder="Enter body variable (e.g., {{ product_name }})"
                                                value="<?php echo htmlspecialchars($body, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php if ($index > 0): ?>
                                                <button type="button" class="remove-btn"
                                                    onclick="removeVariableField(this, 'body')">
                                                    <span><svg xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" width="20"
                                                            height="20" viewBox="0 0 30 30">
                                                            <path
                                                                d="M 14.984375 2.4863281 A 1.0001 1.0001 0 0 0 14 3.5 L 14 4 L 8.5 4 A 1.0001 1.0001 0 0 0 7.4863281 5 L 6 5 A 1.0001 1.0001 0 1 0 6 7 L 24 7 A 1.0001 1.0001 0 1 0 24 5 L 22.513672 5 A 1.0001 1.0001 0 0 0 21.5 4 L 16 4 L 16 3.5 A 1.0001 1.0001 0 0 0 14.984375 2.4863281 z M 6 9 L 7.7929688 24.234375 C 7.9109687 25.241375 8.7633438 26 9.7773438 26 L 20.222656 26 C 21.236656 26 22.088031 25.241375 22.207031 24.234375 L 24 9 L 6 9 z">
                                                            </path>
                                                        </svg></span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="btn-group">
                            <button class="submit-btn"
                                type="submit"><?php echo $isEditMode ? 'Update Template' : 'Save Template'; ?></button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="right-panel">
                <div class="variable-box">
                    <h4>Liquid Variables</h4>
                    <p>Please replace the Template Variable {#var#} with liquid variables mentioned below.</p>
                    <div class="variable-divider"></div>
                    <div class="variable-list">
                        <div class="variable-item">{{ order_name }}</div>
                        <div class="variable-item">{{ order_total_price }}</div>
                        <div class="variable-item">{{ customer_fname }}</div>
                        <div class="variable-item">{{ customer_lname }}</div>
                        <div class="variable-item">{{ tracking_number }}</div>
                        <div class="variable-item">{{ tracking_url }}</div>
                        <div class="variable-item">{{ shipping_address }}</div>
                        <div class="variable-item">{{ shipping_state }}</div>
                        <div class="variable-item">{{ shipping_city }}</div>
                        <div class="variable-item">{{ customer_email_id }}</div>
                        <div class="variable-item">{{ country_code }}</div>
                        <div class="variable-item">{{ customer_phone }}</div>
                        <div class="variable-item">{{ item_name }}</div>
                        <div class="variable-item">{{ customer_full_name }}</div>
                        <div class="variable-item">{{ Ad_order_number }}</div>
                        <div class="variable-item">{{ Ad_total_discount }}</div>
                        <div class="variable-item">{{ Ad_order_status_url }}</div>
                        <div class="variable-item">{{ Ad_item_price }}</div>
                        <div class="variable-item">{{ Ad_item_quantity }}</div>
                        <div class="variable-item">{{ Ad_item_sku }}</div>
                        <div class="variable-item">{{ Ad_item_vendor }}</div>
                        <div class="variable-item">{{ Ad_total_weight }}</div>
                        <div class="variable-item">{{ Ad_shipping_address }}</div>
                        <div class="variable-item">{{ Ad_billing_address }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        var buttonCount = <?php
        $btnCount = 0;
        for ($i = 1; $i <= 3; $i++) {
            if (
                $isEditMode && (
                    (isset($existingData['button_type']) && $i == 1) ||
                    (isset($existingData['button_type2']) && $i == 2) ||
                    (isset($existingData['button_type3']) && $i == 3)
                )
            ) {
                $btnCount++;
            }
        }
        echo max(1, $btnCount);
        ?>;
        var headerCounter = <?php echo max(1, count($variableHeaders)); ?>;
        var bodyCounter = <?php echo max(1, count($variableBody)); ?>;

        function addButton() {
            if (buttonCount >= 3) {
                shopify.toast.show('Maximum 3 buttons allowed', { isError: true, duration: 3000 });
                return;
            }

            buttonCount++;
            var container = document.getElementById('buttons-container');
            var newButtonGroup = document.createElement('div');
            newButtonGroup.className = 'button-group';
            newButtonGroup.id = 'button-group-' + buttonCount;
            newButtonGroup.innerHTML = '<div class="button-header">' +
                '<span class="button-title">Button ' + buttonCount + '</span>' +
                '<button type="button" class="remove-button-btn" onclick="removeButton(' + buttonCount + ')">' +
                '<span><svg xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" width="20" height="20" viewBox="0 0 30 30">' +
                '<path d="M 14.984375 2.4863281 A 1.0001 1.0001 0 0 0 14 3.5 L 14 4 L 8.5 4 A 1.0001 1.0001 0 0 0 7.4863281 5 L 6 5 A 1.0001 1.0001 0 1 0 6 7 L 24 7 A 1.0001 1.0001 0 1 0 24 5 L 22.513672 5 A 1.0001 1.0001 0 0 0 21.5 4 L 16 4 L 16 3.5 A 1.0001 1.0001 0 0 0 14.984375 2.4863281 z M 6 9 L 7.7929688 24.234375 C 7.9109687 25.241375 8.7633438 26 9.7773438 26 L 20.222656 26 C 21.236656 26 22.088031 25.241375 22.207031 24.234375 L 24 9 L 6 9 z"></path>' +
                '</svg></span></button></div>' +
                '<div class="button-row">' +
                '<select name="button_type' + buttonCount + '" class="button-type" data-button="' + buttonCount + '" onchange="toggleUrlField(' + buttonCount + ')">' +
                '<option value="none">None</option>' +
                '<option value="cta">CTA</option>' +
                '<option value="quick">Quick Reply</option>' +
                '</select>' +
                '<input type="text" name="button_text' + buttonCount + '" placeholder="Button Text">' +
                '</div>' +
                '<div class="url-field" id="url-field-' + buttonCount + '">' +
                '<input type="text" name="button_url' + buttonCount + '" placeholder="Enter URL (e.g., https://example.com)">' +
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
                    urlField.classList.add('show');
                    urlField.style.display = 'flex';
                } else {
                    urlField.classList.remove('show');
                    urlField.style.display = 'none';
                }
            }
        }

        function addVariableField(type) {
            var container = type === 'headers' ? document.getElementById('headers-container') : document.getElementById('body-container');
            var counter = type === 'headers' ? headerCounter : bodyCounter;
            var fieldName = type === 'headers' ? 'variable_headers[]' : 'variable_body[]';
            var placeholder = type === 'headers' ? 'Enter header variable (e.g., {{ customer_fname }})' : 'Enter body variable (e.g., {{ product_name }})';

            var newRow = document.createElement('div');
            newRow.className = 'variable-row';
            newRow.setAttribute('data-id', type + '_' + counter);
            newRow.innerHTML = '<input type="text" name="' + fieldName + '" placeholder="' + placeholder + '">' +
                '<button type="button" class="remove-btn" onclick="removeVariableField(this, \'' + type + '\')">' +
                '<span><svg xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" width="20" height="20" viewBox="0 0 30 30">' +
                '<path d="M 14.984375 2.4863281 A 1.0001 1.0001 0 0 0 14 3.5 L 14 4 L 8.5 4 A 1.0001 1.0001 0 0 0 7.4863281 5 L 6 5 A 1.0001 1.0001 0 1 0 6 7 L 24 7 A 1.0001 1.0001 0 1 0 24 5 L 22.513672 5 A 1.0001 1.0001 0 0 0 21.5 4 L 16 4 L 16 3.5 A 1.0001 1.0001 0 0 0 14.984375 2.4863281 z M 6 9 L 7.7929688 24.234375 C 7.9109687 25.241375 8.7633438 26 9.7773438 26 L 20.222656 26 C 21.236656 26 22.088031 25.241375 22.207031 24.234375 L 24 9 L 6 9 z"></path>' +
                '</svg></span></button>';
            container.appendChild(newRow);
            if (type === 'headers') {
                headerCounter++;
            } else {
                bodyCounter++;
            }
        }

        function removeVariableField(button, type) {
            var row = button.parentNode;
            while (row && row.className !== 'variable-row') {
                row = row.parentNode;
            }
            if (row) {
                var container = type === 'headers' ? document.getElementById('headers-container') : document.getElementById('body-container');
                if (container.children.length > 1) {
                    row.remove();
                } else {
                    var input = row.querySelector('input');
                    if (input) {
                        input.value = '';
                    }
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
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
                            try { data = JSON.parse(xhr.responseText); } catch (err) {
                                shopify.toast.show('Invalid server response. Please try again.', { isError: true, duration: 5000 });
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
                                    var redirectUrl = data.redirect_url ? data.redirect_url : "<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/index.php?tab=shipping&shop=<?php echo urlencode($shop); ?>";
                                    window.location.href = redirectUrl;
                                }, 700);
                            } else {
                                shopify.toast.show((data && data.message) ? data.message : 'Failed to save template.', { isError: true, duration: 5000 });
                            }
                        } else {
                            shopify.toast.show('Failed to save template. Please try again.', { isError: true, duration: 5000 });
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
            var forms = document.querySelectorAll('.left-panel form');
            for (var fi = 0; fi < forms.length; fi++) {
                attachAjaxFormSubmission(forms[fi]);
            }

            var variableItems = document.querySelectorAll('.variable-item');
            for (var i = 0; i < variableItems.length; i++) {
                variableItems[i].addEventListener('click', function () {
                    var text = this.innerText.trim();

                    navigator.clipboard.writeText(text)
                        .then(function () {
                            shopify.toast.show('Variable copied to clipboard', { duration: 3000 });
                        })
                        .catch(function () {
                            shopify.toast.show('Failed to copy', { isError: true, duration: 3000 });
                        });
                });
            }
            var tabs = document.querySelectorAll('.tab-btn');
            var contents = document.querySelectorAll('.tab-content');

            for (var i = 0; i < tabs.length; i++) {
                tabs[i].addEventListener('click', (function (tab) {
                    return function () {
                        for (var j = 0; j < tabs.length; j++) {
                            tabs[j].classList.remove('active');
                        }
                        for (var j = 0; j < contents.length; j++) {
                            contents[j].classList.remove('active');
                        }
                        tab.classList.add('active');
                        document.getElementById(tab.dataset.tab).classList.add('active');
                    };
                })(tabs[i]));
            }

            var mediaSourceSection = document.getElementById('mediaSourceBox');
            var urlBox = document.getElementById('mediaUrlBox');
            var fileBox = document.getElementById('mediaFileBox');
            var mediaType = document.getElementById('mediaType');
            var dynamicOption = document.getElementById('dynamicImageOption');
            var radioButtons = document.querySelectorAll('input[name="media_source_type"]');

            // function toggleMediaFields() {
            //     var selected = null;
            //     for (var i = 0; i < radioButtons.length; i++) {
            //         if (radioButtons[i].checked) {
            //             selected = radioButtons[i];
            //             break;
            //         }
            //     }
            //     if (!selected) return;
            //     if (mediaType.value === 'text') {
            //         mediaSourceSection.style.display = 'none';
            //         urlBox.style.display = 'none';
            //         fileBox.style.display = 'none';
            //         dynamicOption.style.display = 'none';
            //         return;
            //     }
            //     mediaSourceSection.style.display = '';
            //     if (mediaType.value === 'image') {
            //         dynamicOption.style.display = 'inline-flex';
            //     } else {
            //         dynamicOption.style.display = 'none';
            //         if (selected.value === 'dynamic') {
            //             for (var i = 0; i < radioButtons.length; i++) {
            //                 if (radioButtons[i].value === 'url') {
            //                     radioButtons[i].checked = true;
            //                     break;
            //                 }
            //             }
            //         }
            //     }
            //     if (selected.value === 'url') {
            //         urlBox.style.display = 'flex';
            //         fileBox.style.display = 'none';
            //     }
            //     else if (selected.value === 'file') {
            //         urlBox.style.display = 'none';
            //         fileBox.style.display = 'flex';
            //     }
            //     else if (selected.value === 'dynamic') {
            //         urlBox.style.display = 'none';
            //         fileBox.style.display = 'none';
            //     }
            // }
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

            for (var i = 0; i < radioButtons.length; i++) {
                radioButtons[i].addEventListener('change', toggleMediaFields);
            }
            mediaType.addEventListener('change', toggleMediaFields);
            toggleMediaFields();
            for (var i = 1; i <= buttonCount; i++) {
                toggleUrlField(i);
            }
        });
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
    <div id="toast" class="toast">Copied to clipboard</div>
</body>

</html>