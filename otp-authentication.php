<?php require_once __DIR__ . '/app_config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="shopify-api-key" content="<?php echo htmlspecialchars($api_key, ENT_QUOTES, 'UTF-8'); ?>">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <link rel="stylesheet" href="templates/styles/style_index.css">
    <title>OTP Authentication</title>
</head>
<body>
    <?php include "layout/header.php"; ?>
    <div class="page-container">
        <div class="table-card">
            <div class="info-banner">
                <span class="info-icon">&#9432;</span>
                These notifications are automatically sent to the customer.
                Click on the notification template to edit the content.
            </div>
            <div class="table-title">OTP Authentication</div>
            <table class="order-table">
                <tbody>
                    <tr>
                        <td>
                            <a href="templates/otp_auth.php">OTP Authentication</a>
                        </td>
                        <td class="description">
                            Send OTP to the customer after they add their mobile number
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>