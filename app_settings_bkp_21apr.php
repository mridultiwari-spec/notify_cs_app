<?php
session_start();
require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/config/db.php';

$shop = isset($_SESSION['shop']) ? $_SESSION['shop'] : (isset($_POST['shop']) ? $_POST['shop'] : (isset($_GET['shop']) ? $_GET['shop'] : ''));
if (!$shop) {
    die("Shop not found");
}

$pdo = getDatabaseConnection();
$table = $prefix . "shopify_sms_notification_app_API_Settings";
$smsSettings = null;
$whatsappSettings = null;
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
            } 
            elseif (!empty($authEmail) && !empty($authPassword)) {
                $tokenResult = generateToken($authEmail, $authPassword, $pdo, $shop, $table);
                if ($tokenResult['success']) {
                    $tokenGenerated = true;
                    $generatedToken = $tokenResult['token'];
                    $tokenMessage = ' New token generated successfully.';
                    error_log("New token generated and saved: " . $generatedToken);
                } else {
                    $tokenMessage = ' Warning: ' . addslashes($tokenResult['error']);
                    error_log("Token generation failed: " . $tokenResult['error']);
                }
            }
            elseif (!empty($authEmail) && empty($authPassword) && $existing && !empty($existing['password'])) {
                $tokenMessage = ' Warning: Password required to generate new token. Your old token may be expired.';
                error_log("Cannot regenerate token: Password required");
            }
        }
        $hashedPassword = null;
        if ($type == 'sms') {
            if (!empty($password)) {
                $hashedPassword = md5($password);
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
        
        echo "<script>window.location.href = window.location.pathname + '?shop=" . urlencode($shop) . "';</script>";
        
    } catch (Exception $e) {
        error_log("Error saving data: " . $e->getMessage());
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
    <title>Settings</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600&display=swap"
        rel="stylesheet">
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&icon_names=arrow_back" />
    <link rel="stylesheet" href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/styles/style_settings.css">
</head>

<body>
    <?php include 'layout/header.php'; ?>
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
                            <select name="country">
                                <option value="India" <?php echo ($smsSettings && $smsSettings['country'] == 'India') ? 'selected' : ''; ?>>India</option>
                                <option value="USA" <?php echo ($smsSettings && $smsSettings['country'] == 'USA') ? 'selected' : ''; ?>>USA</option>
                                <option value="UK" <?php echo ($smsSettings && $smsSettings['country'] == 'UK') ? 'selected' : ''; ?>>UK</option>
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
                            <input type="email" name="email" value="<?php echo htmlspecialchars(isset($smsSettings['email']) ? $smsSettings['email'] : ''); ?>" required>
                            <div class="helper">Enter your account email</div>
                        </div>
                        <div class="form-group">
                            <label>Password</label>
                            <input type="password" name="password" value="" placeholder="Enter your account password" required>
                            <div class="helper">please enter your account password whenever you make changes in app settings</div>
                        </div>
                        <div class="form-group">
                            <label>Sender ID</label>
                            <input type="text" name="details"
                                value="<?php echo htmlspecialchars(isset($smsSettings['details']) ? $smsSettings['details'] : ''); ?>" required>
                            <div class="helper">Your approved SMS Sender ID</div>
                        </div>
                        <div class="form-group">
                            <label>Channel ID</label>
                            <input type="text" name="channel_id"
                                value="<?php echo htmlspecialchars(isset($smsSettings['channel_id']) ? $smsSettings['channel_id'] : ''); ?>">
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
                            <select name="country">
                                <option value="India" <?php echo ($whatsappSettings && $whatsappSettings['country'] == 'India') ? 'selected' : ''; ?>>India</option>
                                <option value="USA" <?php echo ($whatsappSettings && $whatsappSettings['country'] == 'USA') ? 'selected' : ''; ?>>USA</option>
                                <option value="UK" <?php echo ($whatsappSettings && $whatsappSettings['country'] == 'UK') ? 'selected' : ''; ?>>UK</option>
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
                                value="<?php echo htmlspecialchars(isset($whatsappSettings['apikey']) ? $whatsappSettings['apikey'] : ''); ?>">
                            <div class="helper">Use official WhatsApp Business API key</div>
                        </div>
                        <div class="form-group">
                            <label>Sender Number</label>
                            <input type="text" name="details"
                                value="<?php echo htmlspecialchars(isset($whatsappSettings['details']) ? $whatsappSettings['details'] : ''); ?>">
                            <div class="helper">Your WhatsApp Business sender number</div>
                        </div>
                        <div class="form-group">
                            <label>Channel ID</label>
                            <input type="text" name="channel_id"
                                value="<?php echo htmlspecialchars(isset($whatsappSettings['channel_id']) ? $whatsappSettings['channel_id'] : ''); ?>">
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
                    smsForm.querySelector('input[name="email"]').value = '<?php echo addslashes(isset($smsSettings['email']) ? $smsSettings['email'] : ''); ?>';
                    smsForm.querySelector('input[name="password"]').value = '';
                    smsForm.querySelector('input[name="details"]').value = '<?php echo addslashes(isset($smsSettings['details']) ? $smsSettings['details'] : ''); ?>';
                    smsForm.querySelector('input[name="channel_id"]').value = '<?php echo addslashes(isset($smsSettings['channel_id']) ? $smsSettings['channel_id'] : ''); ?>';
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
                    whatsappForm.querySelector('input[name="apikey"]').value = '<?php echo addslashes($whatsappSettings['apikey']); ?>';
                    whatsappForm.querySelector('input[name="details"]').value = '<?php echo addslashes($whatsappSettings['details']); ?>';
                    whatsappForm.querySelector('input[name="channel_id"]').value = '<?php echo addslashes($whatsappSettings['channel_id']); ?>';
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
            fetch('trigger_test.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    country_code: countryCode,
                    phone: phone
                })
            })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.success) {
                        closeTestModal();
                        shopify.toast.show('The test triggered successfully! You can check the status in the View Logs section.', { duration: 3000 });
                    } else {
                        shopify.toast.show(data.message || "Error sending test SMS", { isError: true, duration: 3000 });
                    }
                    setTimeout(function() {
                        sendButton.disabled = false;
                        sendButton.innerHTML = originalText;
                    }, 1000);
                })
                .catch(function(err) {
                    console.error(err);
                    shopify.toast.show("Error sending test SMS", { isError: true, duration: 3000 });
                    sendButton.disabled = false;
                    sendButton.innerHTML = originalText;
                });
        }
    </script>
</body>
</html>