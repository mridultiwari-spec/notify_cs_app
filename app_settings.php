<?php
session_start();
require_once dirname(__FILE__) . '/app_config.php';
require_once dirname(__FILE__) . '/config/db.php';
require_once dirname(__FILE__) . '/config/session_token_auth.php';

$isAjaxRequest = isset($_POST['ajax']) && $_POST['ajax'] == '1';

$shop = isset($_SESSION['shop']) ? $_SESSION['shop'] : (isset($_POST['shop']) ? $_POST['shop'] : (isset($_GET['shop']) ? $_GET['shop'] : ''));
if (!$shop) {
    die("Shop not found");
}

$pdo = getDatabaseConnection();
$table = $prefix . "shopify_sms_notification_app_API_Settings";
$smsSettings = null;
$whatsappSettings = null;
$toastMessage = null;
$toastType = null;

try {
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE shop = :shop");
    $stmt->execute(array(':shop' => $shop));
    $allSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allSettings as $setting) {
        if ($setting['type'] == 'sms') {
            $smsSettings = $setting;
        } elseif ($setting['type'] == 'whatsapp') {
            $whatsappSettings = $setting;
        }
    }
} catch (Exception $e) {
    error_log($e->getMessage());
}
function legacy_password_hash($password)
{
    $salt = substr(md5(uniqid(mt_rand(), true)), 0, 22);
    $salt = str_replace('+', '.', $salt);
    return crypt($password, '$2a$10$' . $salt);
}

function legacy_password_verify($password, $hash)
{
    return crypt($password, $hash) === $hash;
}

function send_json_response($payload)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit();
}

function generateToken($email, $password, $pdo, $shop, $table)
{
    global $table;
    $url = "https://messaginghub.solutions/account/enterprise/login";

    $ch = curl_init($url);
    $postData = http_build_query(array(
        'email' => $email,
        'password' => $password
    ));

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
        'longTermToken: true'
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("API cURL Error: " . $error);
        return array('success' => false, 'error' => $error);
    }

    $result = json_decode($response, true);
    error_log("API Response: " . print_r($result, true));

    if ($httpCode == 200 && isset($result['status']) && $result['status'] === true) {
        $token = isset($result['response']['token']) ? $result['response']['token'] : null;

        if ($token) {
            try {
                $updateStmt = $pdo->prepare("
UPDATE $table
SET api_token = :token,
token_updated_at = NOW(),
updated_at = NOW()
WHERE shop = :shop AND type = 'sms'
");
                $updateResult = $updateStmt->execute(array(
                    ':token' => $token,
                    ':shop' => $shop
                ));

                if ($updateResult) {
                    error_log("Token saved successfully: " . $token);
                    return array('success' => true, 'token' => $token, 'message' => isset($result['message']['message']) ? $result['message']['message'] : 'Login successful');
                } else {
                    error_log("Failed to save token to database");
                    return array('success' => false, 'error' => 'Failed to save token to database');
                }
            } catch (Exception $e) {
                error_log("Database error in generateToken: " . $e->getMessage());
                return array('success' => false, 'error' => 'Database error: ' . $e->getMessage());
            }
        } else {
            return array('success' => false, 'error' => 'No token received from API');
        }
    } else {
        $errorMsg = isset($result['message']['message']) ? $result['message']['message'] : (isset($result['message']) ? $result['message'] : 'Failed to generate token');
        error_log("API Error Response: " . $response);
        return array('success' => false, 'error' => $errorMsg);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = get_bearer_token_php53();
    $tokenValidation = validate_shopify_session_token_php53($token, $api_secret, $api_key);
    if (!$tokenValidation['success']) {
        if ($isAjaxRequest) {
            send_json_response(array('success' => false, 'message' => $tokenValidation['error'], 'toast_type' => 'error'));
        }
        die("Unauthorized request");
    }
    $shop = $tokenValidation['shop'];
    $_SESSION['shop'] = $shop;

    $type = $_POST['type'];
    $country = isset($_POST['country']) ? $_POST['country'] : '';
    $email = isset($_POST['email']) ? $_POST['email'] : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $details = isset($_POST['details']) ? $_POST['details'] : '';
    $channel_id = isset($_POST['channel_id']) ? $_POST['channel_id'] : '';
    $status = isset($_POST['status']) ? 'enabled' : 'disabled';
    $apikey = isset($_POST['apikey']) ? $_POST['apikey'] : '';

    try {
        $checkStmt = $pdo->prepare("SELECT id, email, password FROM $table WHERE shop = :shop AND type = :type");
        $checkStmt->execute(array(
            ':shop' => $shop,
            ':type' => $type
        ));
        $existing = $checkStmt->fetch();

        $tokenGenerated = false;
        $tokenMessage = '';
        $generatedToken = null;

        if ($type == 'sms') {
            $authEmail = !empty($email) ? $email : (isset($existing['email']) ? $existing['email'] : '');
            $authPassword = !empty($password) ? $password : '';
            if (empty($authPassword) && $existing && !empty($existing['password'])) {
                $tokenMessage = ' Warning: Please enter your password to generate a new token.';
                error_log("Password required to regenerate token");
            } elseif (!empty($authEmail) && !empty($authPassword)) {
                $tokenResult = generateToken($authEmail, $authPassword, $pdo, $shop, $table);
                if ($tokenResult['success']) {
                    $tokenGenerated = true;
                    $generatedToken = $tokenResult['token'];
                    $tokenMessage = ' Authentication token generated successfully.';
                    $toastMessage = 'Authentication token generated successfully!';
                    $toastType = 'success';
                    error_log("New token generated and saved: " . $generatedToken);
                } else {
                    $tokenMessage = ' Warning: ' . addslashes($tokenResult['error']);
                    $toastMessage = 'Failed to generate the authentication token. ' . addslashes($tokenResult['error']);
                    $toastType = 'error';
                    error_log("Token generation failed: " . $tokenResult['error']);
                }
            } elseif (!empty($authEmail) && empty($authPassword) && $existing && !empty($existing['password'])) {
                $tokenMessage = ' Warning: Password required to generate new token. Your old token may be expired.';
                error_log("Cannot regenerate token: Password required");
            }
        }
        $hashedPassword = null;
        if ($type == 'sms') {
            if (!empty($password)) {
                $hashedPassword = legacy_password_hash($password);
            } elseif ($existing && !empty($existing['password'])) {
                $hashedPassword = $existing['password'];
            }
        } else {
            $hashedPassword = null;
        }

        if ($existing) {
            $updateFields = array();
            $updateParams = array(
                ':country' => $country,
                ':details' => $details,
                ':channel_id' => $channel_id,
                ':status' => $status,
                ':shop' => $shop,
                ':type' => $type
            );
            if ($type == 'sms') {
                if (!empty($email)) {
                    $updateFields[] = "email = :email";
                    $updateParams[':email'] = $email;
                }
                if ($hashedPassword !== null) {
                    $updateFields[] = "password = :password";
                    $updateParams[':password'] = $hashedPassword;
                }
            } else {
                $updateFields[] = "apikey = :apikey";
                $updateParams[':apikey'] = $apikey;
            }

            if (!empty($updateFields)) {
                $updateSql = "UPDATE $table SET " . implode(", ", $updateFields) . ", country = :country, details = :details, channel_id = :channel_id, status = :status, updated_at = NOW() WHERE shop = :shop AND type = :type";
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute($updateParams);
            } else {
                $updateStmt = $pdo->prepare("
UPDATE $table
SET country = :country,
details = :details,
channel_id = :channel_id,
status = :status,
updated_at = NOW()
WHERE shop = :shop AND type = :type
");
                $updateStmt->execute(array(
                    ':country' => $country,
                    ':details' => $details,
                    ':channel_id' => $channel_id,
                    ':status' => $status,
                    ':shop' => $shop,
                    ':type' => $type
                ));
            }

            $successMessage = 'Settings updated successfully.' . $tokenMessage;

            $stmt = $pdo->prepare("SELECT * FROM $table WHERE shop = :shop AND type = :type");
            $stmt->execute(array(':shop' => $shop, ':type' => $type));
            if ($type == 'sms') {
                $smsSettings = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($smsSettings && !empty($smsSettings['api_token'])) {
                    error_log("Token in database: " . $smsSettings['api_token']);
                } else {
                    error_log("No token found in database");
                }
            } else {
                $whatsappSettings = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        } else {
            if ($type == 'sms') {
                $insertStmt = $pdo->prepare("
INSERT INTO $table
(shop, type, apikey, email, password, details, channel_id, status, country)
VALUES (:shop, :type, :apikey, :email, :password, :details, :channel_id, :status, :country)
");
                $insertResult = $insertStmt->execute(array(
                    ':shop' => $shop,
                    ':type' => $type,
                    ':apikey' => '',
                    ':email' => $email,
                    ':password' => $hashedPassword,
                    ':details' => $details,
                    ':channel_id' => $channel_id,
                    ':status' => $status,
                    ':country' => $country
                ));

            } else {
                $insertStmt = $pdo->prepare("
INSERT INTO $table
(shop, type, apikey, email, password, details, channel_id, status, country)
VALUES (:shop, :type, :apikey, :email, :password, :details, :channel_id, :status, :country)
");
                $insertStmt->execute(array(
                    ':shop' => $shop,
                    ':type' => $type,
                    ':apikey' => $apikey,
                    ':email' => null,
                    ':password' => null,
                    ':details' => $details,
                    ':channel_id' => $channel_id,
                    ':status' => $status,
                    ':country' => $country
                ));
            }

            $successMessage = 'Settings saved successfully.' . $tokenMessage;

            $stmt = $pdo->prepare("SELECT * FROM $table WHERE shop = :shop AND type = :type");
            $stmt->execute(array(':shop' => $shop, ':type' => $type));
            if ($type == 'sms') {
                $smsSettings = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $whatsappSettings = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
        if ($isAjaxRequest) {
            send_json_response(array(
                'success' => true,
                'message' => $toastMessage ? $toastMessage : $successMessage,
                'toast_type' => $toastType ? $toastType : 'success'
            ));
        }

        $redirectUrl = $_SERVER['PHP_SELF'] . '?shop=' . urlencode($shop);
        echo "<script>window.location.href = '" . $redirectUrl . "';</script>";
        exit();

    } catch (Exception $e) {
        error_log("Error saving data: " . $e->getMessage());
        if ($isAjaxRequest) {
            send_json_response(array(
                'success' => false,
                'message' => 'Failed to save settings. Please try again.',
                'toast_type' => 'error'
            ));
        }
    }
}
function js_escape($str)
{
    return str_replace(array("\\", "'", '"', "\n", "\r", "\t"), array("\\\\", "\\'", '\\"', "\\n", "\\r", "\\t"), $str);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="shopify-api-key" content="<?php echo htmlspecialchars($api_key, ENT_QUOTES, 'UTF-8'); ?>">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <title>Settings</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600&display=swap"
        rel="stylesheet">
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&icon_names=arrow_back" />
    <link rel="stylesheet"
        href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/styles/style_settings.css">
</head>

<body>
    <?php if (!$isAjaxRequest) {
        include 'layout/header.php';
    } ?>
    <div class="page-header"></div>
    <div class="wrapper">
        <div class="sidebar">
            <div class="sidebar-box">
                <p><span>&#9432;</span> To Setup SMS and WhatsApp Notify App, you must have Email, Password,
                    SMS
                    Templates Whitelisted from TRAI(As per guideline), SenderId. If you need help to get this
                    information, please connect to
                    Team at : <br><br>
                    Email: marketing@messaginghub.com <br>
                    Phone: +91 22 40556161
                </p>
            </div>
        </div>
        <div class="main">
            <div class="card">
                <div class="tabs">
                    <button class="tab-btn active" onclick="openTab('sms', this)">SMS</button>
                    <button class="tab-btn" onclick="openTab('whatsapp', this)">WhatsApp</button>
                </div>
                <div id="sms" class="tab-content active">
                    <form method="POST" action="">
                        <input type="hidden" name="type" value="sms">
                        <div class="form-group">
                            <label>Select Country</label>
                            <input type="text" class="country-search-input" placeholder="Search country..."
                                value="<?php echo htmlspecialchars(($smsSettings && isset($smsSettings['country'])) ? $smsSettings['country'] : 'India', ENT_QUOTES, 'UTF-8'); ?>"
                                onclick="toggleCountryDropdown(this)" onkeyup="filterCountry(this)" autocomplete="off">
                            <select name="country" class="country-select" size="6" onchange="selectCountry(this)">
                                <?php
                                $countries = array(
                                    'Afghanistan',
                                    'Albania',
                                    'Algeria',
                                    'Andorra',
                                    'Angola',
                                    'Antigua and Barbuda',
                                    'Argentina',
                                    'Armenia',
                                    'Australia',
                                    'Austria',
                                    'Azerbaijan',
                                    'Bahamas',
                                    'Bahrain',
                                    'Bangladesh',
                                    'Barbados',
                                    'Belarus',
                                    'Belgium',
                                    'Belize',
                                    'Benin',
                                    'Bhutan',
                                    'Bolivia',
                                    'Bosnia and Herzegovina',
                                    'Botswana',
                                    'Brazil',
                                    'Brunei',
                                    'Bulgaria',
                                    'Burkina Faso',
                                    'Burundi',
                                    'Cambodia',
                                    'Cameroon',
                                    'Canada',
                                    'Cape Verde',
                                    'Central African Republic',
                                    'Chad',
                                    'Chile',
                                    'China',
                                    'Colombia',
                                    'Comoros',
                                    'Congo',
                                    'Costa Rica',
                                    'Croatia',
                                    'Cuba',
                                    'Cyprus',
                                    'Czech Republic',
                                    'Denmark',
                                    'Djibouti',
                                    'Dominica',
                                    'Dominican Republic',
                                    'Ecuador',
                                    'Egypt',
                                    'El Salvador',
                                    'Equatorial Guinea',
                                    'Eritrea',
                                    'Estonia',
                                    'Ethiopia',
                                    'Fiji',
                                    'Finland',
                                    'France',
                                    'Gabon',
                                    'Gambia',
                                    'Georgia',
                                    'Germany',
                                    'Ghana',
                                    'Greece',
                                    'Grenada',
                                    'Guatemala',
                                    'Guinea',
                                    'Guinea-Bissau',
                                    'Guyana',
                                    'Haiti',
                                    'Honduras',
                                    'Hungary',
                                    'Iceland',
                                    'India',
                                    'Indonesia',
                                    'Iran',
                                    'Iraq',
                                    'Ireland',
                                    'Israel',
                                    'Italy',
                                    'Jamaica',
                                    'Japan',
                                    'Jordan',
                                    'Kazakhstan',
                                    'Kenya',
                                    'Kiribati',
                                    'North Korea',
                                    'South Korea',
                                    'Kosovo',
                                    'Kuwait',
                                    'Kyrgyzstan',
                                    'Laos',
                                    'Latvia',
                                    'Lebanon',
                                    'Lesotho',
                                    'Liberia',
                                    'Libya',
                                    'Liechtenstein',
                                    'Lithuania',
                                    'Luxembourg',
                                    'Macedonia',
                                    'Madagascar',
                                    'Malawi',
                                    'Malaysia',
                                    'Maldives',
                                    'Mali',
                                    'Malta',
                                    'Marshall Islands',
                                    'Mauritania',
                                    'Mauritius',
                                    'Mexico',
                                    'Micronesia',
                                    'Moldova',
                                    'Monaco',
                                    'Mongolia',
                                    'Montenegro',
                                    'Morocco',
                                    'Mozambique',
                                    'Myanmar',
                                    'Namibia',
                                    'Nauru',
                                    'Nepal',
                                    'Netherlands',
                                    'New Zealand',
                                    'Nicaragua',
                                    'Niger',
                                    'Nigeria',
                                    'Norway',
                                    'Oman',
                                    'Pakistan',
                                    'Palau',
                                    'Palestine',
                                    'Panama',
                                    'Papua New Guinea',
                                    'Paraguay',
                                    'Peru',
                                    'Philippines',
                                    'Poland',
                                    'Portugal',
                                    'Qatar',
                                    'Romania',
                                    'Russia',
                                    'Rwanda',
                                    'Saint Kitts and Nevis',
                                    'Saint Lucia',
                                    'Saint Vincent',
                                    'Samoa',
                                    'San Marino',
                                    'Sao Tome and Principe',
                                    'Saudi Arabia',
                                    'Senegal',
                                    'Serbia',
                                    'Seychelles',
                                    'Sierra Leone',
                                    'Singapore',
                                    'Slovakia',
                                    'Slovenia',
                                    'Solomon Islands',
                                    'Somalia',
                                    'South Africa',
                                    'South Sudan',
                                    'Spain',
                                    'Sri Lanka',
                                    'Sudan',
                                    'Suriname',
                                    'Swaziland',
                                    'Sweden',
                                    'Switzerland',
                                    'Syria',
                                    'Taiwan',
                                    'Tajikistan',
                                    'Tanzania',
                                    'Thailand',
                                    'Timor-Leste',
                                    'Togo',
                                    'Tonga',
                                    'Trinidad and Tobago',
                                    'Tunisia',
                                    'Turkey',
                                    'Turkmenistan',
                                    'Tuvalu',
                                    'Uganda',
                                    'Ukraine',
                                    'United Arab Emirates',
                                    'United Kingdom',
                                    'United States',
                                    'Uruguay',
                                    'Uzbekistan',
                                    'Vanuatu',
                                    'Venezuela',
                                    'Vietnam',
                                    'Yemen',
                                    'Zambia',
                                    'Zimbabwe'
                                );
                                $selectedCountry = ($smsSettings && isset($smsSettings['country'])) ? $smsSettings['country'] : 'India';
                                foreach ($countries as $country) {
                                    $selected = ($country == $selectedCountry) ? ' selected' : '';
                                    echo '<option value="' . htmlspecialchars($country, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($country, ENT_QUOTES, 'UTF-8') . '</option>' . "\n";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group toggle-inline">
                            <label>Enable / Disable SMS</label>
                            <label class="toggle">
                                <input type="checkbox" name="status" <?php echo ($smsSettings && $smsSettings['status'] == 'enabled') ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email"
                                value="<?php echo htmlspecialchars(isset($smsSettings['email']) ? $smsSettings['email'] : '', ENT_QUOTES, 'UTF-8'); ?>"
                                required>
                            <div class="helper">Enter your account email</div>
                        </div>
                        <div class="form-group">
                            <label>Password</label>
                            <input type="password" name="password" value="" placeholder="Enter your account password"
                                required>
                            <div class="helper">please enter your account password whenever you make changes in app
                                settings</div>
                        </div>
                        <div class="form-group">
                            <label>Sender ID</label>
                            <input type="text" name="details"
                                value="<?php echo htmlspecialchars(isset($smsSettings['details']) ? $smsSettings['details'] : '', ENT_QUOTES, 'UTF-8'); ?>"
                                required>
                            <div class="helper">Your approved SMS Sender ID</div>
                        </div>
                        <div class="form-group">
                            <label>Channel ID</label>
                            <input type="text" name="channel_id"
                                value="<?php echo htmlspecialchars(isset($smsSettings['channel_id']) ? $smsSettings['channel_id'] : '', ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="helper">Channel ID (optional)</div>
                        </div>
                        <div class="btn-group">
                            <button type="submit" class="submit-btn">Save Settings</button>
                        </div>
                    </form>
                </div>
                <div id="whatsapp" class="tab-content">
                    <form method="POST" action="">
                        <input type="hidden" name="type" value="whatsapp">
                        <div class="form-group">
                            <label>Select Country</label>
                            <input type="text" class="country-search-input" placeholder="Search country..."
                                value="<?php echo htmlspecialchars(($whatsappSettings && isset($whatsappSettings['country'])) ? $whatsappSettings['country'] : 'India', ENT_QUOTES, 'UTF-8'); ?>"
                                onclick="toggleCountryDropdown(this)" onkeyup="filterCountry(this)" autocomplete="off">
                            <select name="country" class="country-select" size="6" onchange="selectCountry(this)">
                                <?php
                                $countries = array(
                                    'Afghanistan',
                                    'Albania',
                                    'Algeria',
                                    'Andorra',
                                    'Angola',
                                    'Antigua and Barbuda',
                                    'Argentina',
                                    'Armenia',
                                    'Australia',
                                    'Austria',
                                    'Azerbaijan',
                                    'Bahamas',
                                    'Bahrain',
                                    'Bangladesh',
                                    'Barbados',
                                    'Belarus',
                                    'Belgium',
                                    'Belize',
                                    'Benin',
                                    'Bhutan',
                                    'Bolivia',
                                    'Bosnia and Herzegovina',
                                    'Botswana',
                                    'Brazil',
                                    'Brunei',
                                    'Bulgaria',
                                    'Burkina Faso',
                                    'Burundi',
                                    'Cambodia',
                                    'Cameroon',
                                    'Canada',
                                    'Cape Verde',
                                    'Central African Republic',
                                    'Chad',
                                    'Chile',
                                    'China',
                                    'Colombia',
                                    'Comoros',
                                    'Congo',
                                    'Costa Rica',
                                    'Croatia',
                                    'Cuba',
                                    'Cyprus',
                                    'Czech Republic',
                                    'Denmark',
                                    'Djibouti',
                                    'Dominica',
                                    'Dominican Republic',
                                    'Ecuador',
                                    'Egypt',
                                    'El Salvador',
                                    'Equatorial Guinea',
                                    'Eritrea',
                                    'Estonia',
                                    'Ethiopia',
                                    'Fiji',
                                    'Finland',
                                    'France',
                                    'Gabon',
                                    'Gambia',
                                    'Georgia',
                                    'Germany',
                                    'Ghana',
                                    'Greece',
                                    'Grenada',
                                    'Guatemala',
                                    'Guinea',
                                    'Guinea-Bissau',
                                    'Guyana',
                                    'Haiti',
                                    'Honduras',
                                    'Hungary',
                                    'Iceland',
                                    'India',
                                    'Indonesia',
                                    'Iran',
                                    'Iraq',
                                    'Ireland',
                                    'Israel',
                                    'Italy',
                                    'Jamaica',
                                    'Japan',
                                    'Jordan',
                                    'Kazakhstan',
                                    'Kenya',
                                    'Kiribati',
                                    'North Korea',
                                    'South Korea',
                                    'Kosovo',
                                    'Kuwait',
                                    'Kyrgyzstan',
                                    'Laos',
                                    'Latvia',
                                    'Lebanon',
                                    'Lesotho',
                                    'Liberia',
                                    'Libya',
                                    'Liechtenstein',
                                    'Lithuania',
                                    'Luxembourg',
                                    'Macedonia',
                                    'Madagascar',
                                    'Malawi',
                                    'Malaysia',
                                    'Maldives',
                                    'Mali',
                                    'Malta',
                                    'Marshall Islands',
                                    'Mauritania',
                                    'Mauritius',
                                    'Mexico',
                                    'Micronesia',
                                    'Moldova',
                                    'Monaco',
                                    'Mongolia',
                                    'Montenegro',
                                    'Morocco',
                                    'Mozambique',
                                    'Myanmar',
                                    'Namibia',
                                    'Nauru',
                                    'Nepal',
                                    'Netherlands',
                                    'New Zealand',
                                    'Nicaragua',
                                    'Niger',
                                    'Nigeria',
                                    'Norway',
                                    'Oman',
                                    'Pakistan',
                                    'Palau',
                                    'Palestine',
                                    'Panama',
                                    'Papua New Guinea',
                                    'Paraguay',
                                    'Peru',
                                    'Philippines',
                                    'Poland',
                                    'Portugal',
                                    'Qatar',
                                    'Romania',
                                    'Russia',
                                    'Rwanda',
                                    'Saint Kitts and Nevis',
                                    'Saint Lucia',
                                    'Saint Vincent',
                                    'Samoa',
                                    'San Marino',
                                    'Sao Tome and Principe',
                                    'Saudi Arabia',
                                    'Senegal',
                                    'Serbia',
                                    'Seychelles',
                                    'Sierra Leone',
                                    'Singapore',
                                    'Slovakia',
                                    'Slovenia',
                                    'Solomon Islands',
                                    'Somalia',
                                    'South Africa',
                                    'South Sudan',
                                    'Spain',
                                    'Sri Lanka',
                                    'Sudan',
                                    'Suriname',
                                    'Swaziland',
                                    'Sweden',
                                    'Switzerland',
                                    'Syria',
                                    'Taiwan',
                                    'Tajikistan',
                                    'Tanzania',
                                    'Thailand',
                                    'Timor-Leste',
                                    'Togo',
                                    'Tonga',
                                    'Trinidad and Tobago',
                                    'Tunisia',
                                    'Turkey',
                                    'Turkmenistan',
                                    'Tuvalu',
                                    'Uganda',
                                    'Ukraine',
                                    'United Arab Emirates',
                                    'United Kingdom',
                                    'United States',
                                    'Uruguay',
                                    'Uzbekistan',
                                    'Vanuatu',
                                    'Venezuela',
                                    'Vietnam',
                                    'Yemen',
                                    'Zambia',
                                    'Zimbabwe'
                                );
                                $selectedCountry = ($whatsappSettings && isset($whatsappSettings['country'])) ? $whatsappSettings['country'] : 'India';
                                foreach ($countries as $country) {
                                    $selected = ($country == $selectedCountry) ? ' selected' : '';
                                    echo '<option value="' . htmlspecialchars($country, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($country, ENT_QUOTES, 'UTF-8') . '</option>' . "\n";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group toggle-inline">
                            <label>Enable / Disable WhatsApp</label>
                            <label class="toggle">
                                <input type="checkbox" name="status" <?php echo ($whatsappSettings && $whatsappSettings['status'] == 'enabled') ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="form-group">
                            <label>API Key</label>
                            <input type="text" name="apikey"
                                value="<?php echo htmlspecialchars(isset($whatsappSettings['apikey']) ? $whatsappSettings['apikey'] : '', ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="helper">Use official WhatsApp Business API key</div>
                        </div>
                        <div class="form-group">
                            <label>Domain</label>
                            <input type="text" name="details"
                                value="<?php echo htmlspecialchars(isset($whatsappSettings['details']) ? $whatsappSettings['details'] : '', ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="helper">Your Domain</div>
                        </div>
                        <div class="form-group">
                            <label>Channel ID</label>
                            <input type="text" name="channel_id"
                                value="<?php echo htmlspecialchars(isset($whatsappSettings['channel_id']) ? $whatsappSettings['channel_id'] : '', ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="helper">WhatsApp channel ID (optional)</div>
                        </div>
                        <div class="btn-group">
                            <button type="submit" class="submit-btn">Save Settings</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div id="testModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeTestModal()">&times;</span>
                <h3>Send Test SMS</h3>

                <div class="form-group">
                    <label>Country Code</label>
                    <select id="test_country_code">
                        <option value="+91">India (+91)</option>
                        <option value="+1">USA (+1)</option>
                        <option value="+44">UK (+44)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" id="test_phone" placeholder="Enter phone number">
                </div>
                <div class="btn-group">
                    <button onclick="sendTestSMS()" class="submit-btn">Send Test</button>
                </div>
            </div>
        </div>
    </div>
    <style>
        .token-status {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-badge.active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.expired {
            background: #fee2e2;
            color: #991b1b;
        }

        .loading-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #ffffff;
            border-top: 2px solid transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-right: 8px;
            vertical-align: middle;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .toast-message {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #10b981;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 14px;
            z-index: 9999;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }

        .toast-message.error {
            background: #ef4444;
        }

        .toast-message.show {
            opacity: 1;
        }

        .country-search-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            box-sizing: border-box;
            cursor: pointer;
        }

        .country-search-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }

        .country-select {
            width: 100%;
            max-height: 180px;
            display: none;
            margin-top: 4px;
        }

        .country-select.show {
            display: block;
        }
    </style>
    <script>
        function openTab(tab, el) {
            var contents = document.querySelectorAll('.tab-content');
            var buttons = document.querySelectorAll('.tab-btn');
            for (var i = 0; i < contents.length; i++) {
                contents[i].classList.remove('active');
            }
            for (var i = 0; i < buttons.length; i++) {
                buttons[i].classList.remove('active');
            }
            document.getElementById(tab).classList.add('active');
            el.classList.add('active');
        }

        function resetForm(type) {
            if (type === 'sms') {
                <?php if ($smsSettings): ?>
                    var smsForm = document.querySelector('#sms form');
                    smsForm.querySelector('select[name="country"]').value = '<?php echo $smsSettings['country']; ?>';
                    smsForm.querySelector('input[name="status"]').checked = <?php echo $smsSettings['status'] == 'enabled' ? 'true' : 'false'; ?>;
                    smsForm.querySelector('input[name="email"]').value = '<?php echo js_escape(isset($smsSettings['email']) ? $smsSettings['email'] : ''); ?>';
                    smsForm.querySelector('input[name="password"]').value = '';
                    smsForm.querySelector('input[name="details"]').value = '<?php echo js_escape(isset($smsSettings['details']) ? $smsSettings['details'] : ''); ?>';
                    smsForm.querySelector('input[name="channel_id"]').value = '<?php echo js_escape(isset($smsSettings['channel_id']) ? $smsSettings['channel_id'] : ''); ?>';
                <?php else: ?>
                    var smsForm = document.querySelector('#sms form');
                    smsForm.querySelector('select[name="country"]').value = 'India';
                    smsForm.querySelector('input[name="status"]').checked = false;
                    smsForm.querySelector('input[name="email"]').value = '';
                    smsForm.querySelector('input[name="password"]').value = '';
                    smsForm.querySelector('input[name="details"]').value = '';
                    smsForm.querySelector('input[name="channel_id"]').value = '';
                <?php endif; ?>
            } else {
                <?php if ($whatsappSettings): ?>
                    var whatsappForm = document.querySelector('#whatsapp form');
                    whatsappForm.querySelector('select[name="country"]').value = '<?php echo $whatsappSettings['country']; ?>';
                    whatsappForm.querySelector('input[name="status"]').checked = <?php echo $whatsappSettings['status'] == 'enabled' ? 'true' : 'false'; ?>;
                    whatsappForm.querySelector('input[name="apikey"]').value = '<?php echo js_escape($whatsappSettings['apikey']); ?>';
                    whatsappForm.querySelector('input[name="details"]').value = '<?php echo js_escape($whatsappSettings['details']); ?>';
                    whatsappForm.querySelector('input[name="channel_id"]').value = '<?php echo js_escape($whatsappSettings['channel_id']); ?>';
                <?php else: ?>
                    var whatsappForm = document.querySelector('#whatsapp form');
                    whatsappForm.querySelector('select[name="country"]').value = 'India';
                    whatsappForm.querySelector('input[name="status"]').checked = false;
                    whatsappForm.querySelector('input[name="apikey"]').value = '';
                    whatsappForm.querySelector('input[name="details"]').value = '';
                    whatsappForm.querySelector('input[name="channel_id"]').value = '';
                <?php endif; ?>
            }
        }

        function openTestModal() {
            document.getElementById('testModal').style.display = 'block';
        }

        function closeTestModal() {
            document.getElementById('testModal').style.display = 'none';
        }

        function sendTestSMS() {
            var countryCode = document.getElementById('test_country_code').value;
            var phone = document.getElementById('test_phone').value;
            if (!phone) {
                shopify.toast.show("Please enter phone number", { isError: true, duration: 3000 });
                return;
            }
            var sendButton = document.querySelector('#testModal .submit-btn');
            var originalText = sendButton.innerHTML;
            sendButton.disabled = true;
            sendButton.innerHTML = '<span class="loading-spinner"></span> Sending...';

            // Use XMLHttpRequest for PHP 5.3 compatibility (fetch is not supported in older browsers)
            getShopifySessionToken(function (sessionToken) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'trigger_test.php', true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                if (sessionToken) {
                    xhr.setRequestHeader('Authorization', 'Bearer ' + sessionToken);
                }
                xhr.onreadystatechange = function () {
                    if (xhr.readyState === 4) {
                        if (xhr.status === 200) {
                            var data = JSON.parse(xhr.responseText);
                            if (data.success) {
                                closeTestModal();
                                shopify.toast.show('The test triggered successfully! You can check the status in the View Logs section.', { duration: 3000 });
                            } else {
                                shopify.toast.show(data.message || "Error sending test SMS", { isError: true, duration: 3000 });
                            }
                        } else {
                            shopify.toast.show("Error sending test SMS", { isError: true, duration: 3000 });
                        }
                        setTimeout(function () {
                            sendButton.disabled = false;
                            sendButton.innerHTML = originalText;
                        }, 1000);
                    }
                };
                xhr.send(JSON.stringify({
                    country_code: countryCode,
                    phone: phone
                }));
            });
        }

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

        function attachSettingsAjaxSubmission(formSelector) {
            var form = document.querySelector(formSelector);
            if (!form) return;

            form.addEventListener('submit', function (e) {
                e.preventDefault();

                var submitButton = form.querySelector('.submit-btn');
                var originalText = submitButton ? submitButton.innerHTML : 'Save Settings';
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.innerHTML = '<span class="loading-spinner"></span> Saving...';
                }

                var pairs = [];
                var elements = form.elements;
                for (var i = 0; i < elements.length; i++) {
                    var field = elements[i];
                    if (!field.name) {
                        continue;
                    }
                    if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) {
                        continue;
                    }
                    pairs.push(encodeURIComponent(field.name) + '=' + encodeURIComponent(field.value));
                }
                pairs.push('ajax=1');

                var xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.pathname + window.location.search, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
                xhr.onreadystatechange = function () {
                    if (xhr.readyState !== 4) {
                        return;
                    }

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

                        if (data && data.message) {
                            shopify.toast.show(data.message, {
                                isError: data.toast_type === 'error' || data.success === false,
                                duration: 3000
                            });
                            if (data.success && formSelector === '#sms form') {
                                var passwordInput = form.querySelector('input[name="password"]');
                                if (passwordInput) {
                                    passwordInput.value = '';
                                }
                            }
                        } else {
                            shopify.toast.show('Unable to save settings. Please try again.', { isError: true, duration: 3000 });
                        }
                    } else {
                        shopify.toast.show('Error saving settings. Please try again.', { isError: true, duration: 3000 });
                    }
                };
                xhrSendWithToken(xhr, pairs.join('&'));
            });
        }

        function xhrSendWithToken(xhr, body) {
            getShopifySessionToken(function (sessionToken) {
                if (sessionToken) {
                    xhr.setRequestHeader('Authorization', 'Bearer ' + sessionToken);
                }
                xhr.send(body);
            });
        }

        window.addEventListener('DOMContentLoaded', function () {
            attachSettingsAjaxSubmission('#sms form');
            attachSettingsAjaxSubmission('#whatsapp form');
        });
        function toggleCountryDropdown(input) {
            var select = findCountrySelect(input);
            if (!select) return;

            // Hide all other country dropdowns first
            var allSelects = document.querySelectorAll('.country-select');
            for (var i = 0; i < allSelects.length; i++) {
                if (allSelects[i] !== select) {
                    allSelects[i].classList.remove('show');
                }
            }

            // Toggle this dropdown
            if (select.classList.contains('show')) {
                select.classList.remove('show');
            } else {
                select.classList.add('show');
                // Show all options
                var options = select.options;
                for (var i = 0; i < options.length; i++) {
                    options[i].style.display = '';
                }
                // Only clear if input doesn't already have a valid country selected
                var hasValidCountry = false;
                for (var i = 0; i < options.length; i++) {
                    if (options[i].text === input.value) {
                        hasValidCountry = true;
                        break;
                    }
                }
                if (!hasValidCountry) {
                    input.value = '';
                }
            }
        }

        function selectCountry(select) {
            var input = findCountryInput(select);
            if (!input) return;

            // Set the input value to selected country
            var selectedOption = select.options[select.selectedIndex];
            if (selectedOption) {
                input.value = selectedOption.text;
            }

            // Hide the dropdown
            select.classList.remove('show');
        }

        function filterCountry(input) {
            var select = findCountrySelect(input);
            if (!select) return;

            var filter = input.value.toLowerCase();
            var options = select.options;

            for (var i = 0; i < options.length; i++) {
                var text = options[i].text.toLowerCase();
                if (text.indexOf(filter) > -1) {
                    options[i].style.display = '';
                } else {
                    options[i].style.display = 'none';
                }
            }
        }

        function findCountrySelect(input) {
            var select = input.nextElementSibling;
            if (select && select.tagName === 'SELECT') return select;

            var next = input.nextSibling;
            while (next) {
                if (next.tagName === 'SELECT') return next;
                next = next.nextSibling;
            }
            return null;
        }

        function findCountryInput(select) {
            var input = select.previousElementSibling;
            if (input && input.tagName === 'INPUT') return input;

            var prev = select.previousSibling;
            while (prev) {
                if (prev.tagName === 'INPUT') return prev;
                prev = prev.previousSibling;
            }
            return null;
        }

        document.addEventListener('click', function (event) {
            var target = event.target;
            if (!target.classList.contains('country-search-input') && target.tagName !== 'OPTION') {
                var allSelects = document.querySelectorAll('.country-select');
                for (var i = 0; i < allSelects.length; i++) {
                    allSelects[i].classList.remove('show');
                }
            }
        });
    </script>
</body>

</html>