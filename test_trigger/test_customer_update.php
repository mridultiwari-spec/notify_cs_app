<?php
require_once '../config/db.php';
include '../accurate_country_code.php';
include '../send_sms_api.php';
require_once '../app_config.php';
include '../send_whatsapp_message_api.php';

header('Content-Type: application/json');

$notification_type = "Customer Account Invite";
$data = json_decode(file_get_contents("php://input"), true);

$countryCode = isset($data['country_code']) ? $data['country_code'] : '';
$phone = isset($data['phone']) ? $data['phone'] : '';
$shop = isset($data['shop']) ? $data['shop'] : '';

if (!$countryCode || !$phone) {
    echo json_encode(array('success' => false, 'message' => 'Missing country code or phone number'));
    exit;
}

if (!$shop) {
    session_start();
    $shop = isset($_SESSION['shop']) ? $_SESSION['shop'] : '';
}

if (!$shop) {
    echo json_encode(array('success' => false, 'message' => 'Shop not found'));
    exit;
}
session_write_close();
try {
    $pdo = getDatabaseConnection();
    $prefix = $app_prefix;
    $table = $prefix . "shopify_sms_notification_app";
    $stmt = $pdo->prepare("SELECT session_access_token AS oauth_token FROM $table WHERE shop = :shop");
    $stmt->execute(array(':shop' => $shop));
    $shopRow = $stmt->fetch();

    if (!$shopRow) {
        echo json_encode(array('success' => false, 'message' => 'Shop not found in database'));
        exit;
    }
    $customer = json_decode('{
        "id": 706405506930370084,
        "created_at": "2021-12-31T19:00:00-05:00",
        "updated_at": "2021-12-31T19:00:00-05:00",
        "first_name": "Bob",
        "last_name": "Biller",
        "state": "disabled",
        "note": "This customer loves ice cream",
        "verified_email": true,
        "multipass_identifier": null,
        "tax_exempt": false,
        "email": "bob@biller.com",
        "phone": null,
        "currency": "USD",
        "addresses": [],
        "tax_exemptions": [],
        "admin_graphql_api_id": "gid://shopify/Customer/706405506930370084",
        "default_address": {
            "id": 12321,
            "customer_id": 706405506930370084,
            "first_name": "Bob",
            "last_name": "Biller",
            "company": null,
            "address1": "151 O\'Connor Street",
            "address2": null,
            "city": "Ottawa",
            "province": "ON",
            "country": "CA",
            "zip": "K2P 2L8",
            "phone": "555-555-5555",
            "name": "Bob Biller",
            "province_code": "ON",
            "country_code": "CA",
            "country_name": "CA",
            "default": true
        }
    }');

    if ($customer === null) {
        echo json_encode(array('success' => false, 'message' => 'Failed to parse customer JSON'));
        exit;
    }

    $customer_id = isset($customer->id) ? $customer->id : '';
    $customer_email = isset($customer->email) ? $customer->email : '';
    $customer_fname = isset($customer->first_name) ? $customer->first_name : '';
    $customer_lname = isset($customer->last_name) ? $customer->last_name : '';
    $customer_full_name = trim($customer_fname . ' ' . $customer_lname);
    $customer_state = isset($customer->state) ? $customer->state : '';
    $customer_note = isset($customer->note) ? $customer->note : '';
    $customer_verified_email = isset($customer->verified_email) ? ($customer->verified_email ? 'Yes' : 'No') : '';

    $default_address = isset($customer->default_address) ? $customer->default_address : null;

    $default_address_string = '';
    if ($default_address) {
        $default_address_string = implode(', ', array_filter(array(
            isset($default_address->first_name) ? $default_address->first_name : '',
            isset($default_address->last_name) ? $default_address->last_name : '',
            isset($default_address->address1) ? $default_address->address1 : '',
            isset($default_address->address2) ? $default_address->address2 : '',
            isset($default_address->city) ? $default_address->city : '',
            isset($default_address->province) ? $default_address->province : '',
            isset($default_address->zip) ? $default_address->zip : '',
            isset($default_address->country) ? $default_address->country : '',
            isset($default_address->phone) ? $default_address->phone : ''
        )));
    }

    $default_address_country_code = ($default_address && isset($default_address->country_code)) ? strtoupper($default_address->country_code) : '';

    $customer_phone = $phone;
    $country_code_input = str_replace('+', '', $countryCode);

    if ($default_address_country_code) {
        $country_code_from_payload = $default_address_country_code;
    } else {
        $country_code_from_payload = 'IN';
    }

    if ($country_code_input && $customer_phone) {
        $final_arr = getCountryCode_and_phone_number($country_code_input, $customer_phone);
        $final_country_code = $final_arr['country_code'];
        $final_phone_number = $final_arr['phone_number'];
    } else {
        $final_country_code = $country_code_input;
        $final_phone_number = $customer_phone;
    }

    $account_invite_link = "https://{$shop}/account/login";

    $actual_values = array(
        "{{ email_id }}" => $customer_email,
        "{{ customer_fname }}" => $customer_fname,
        "{{ customer_lname }}" => $customer_lname,
        "{{ customer_full_name }}" => $customer_full_name,
        "{{ phone_number }}" => $final_phone_number,
        "{{ country_code }}" => $final_country_code,
    );

    error_log("Actual values for replacement: " . print_r($actual_values, true));

    $aid = 9;
    $table = $prefix . "shopify_sms_notification_App_Email_Notification";

    $stmt = $pdo->prepare("SELECT * FROM $table WHERE aid=:aid AND shop=:shop");
    $stmt->execute(array(':aid' => $aid, ':shop' => $shop));
    $row = $stmt->fetch();

    if (!$row) {
        echo json_encode(array('success' => false, 'message' => 'Template not found for Customer Account Invite (aid=9)'));
        exit;
    }

    $sms_text = $row['sms'];

    error_log("Original SMS text: " . $sms_text);

    $processed_sms_text = str_replace(
        array_keys($actual_values),
        array_values($actual_values),
        $sms_text
    );

    error_log("Processed SMS text: " . $processed_sms_text);

    $template_name_sms = $row['template_name'];
    $parameter_values = array();

    if (!empty($row['sms_variables'])) {
        $sms_variables = json_decode($row['sms_variables'], true);

        if (is_array($sms_variables)) {
            foreach ($sms_variables as $key => $val) {
                $parameter_values[$key] = str_replace(
                    array_keys($actual_values),
                    array_values($actual_values),
                    $val
                );
            }
        }
    }
    $sms_enabled = isset($row['sms_enabled']) ? (int) $row['sms_enabled'] : 0;
    $has_parameters = (count($parameter_values) > 0);
    $whatsapp_enabled = isset($row['whatsapp_enabled']) ? (int) $row['whatsapp_enabled'] : 0;
    if ($sms_enabled == 1) {
        if ($has_parameters) {
            send_smstext_with_parameters(
                $final_country_code,
                $final_phone_number,
                $template_name_sms,
                $shop,
                $customer_email,
                $customer_full_name,
                $parameter_values,
                $customer_id,
                $customer_full_name,
                $notification_type
            );

            $response = array(
                'success' => true,
                'message' => 'Template SMS sent successfully',
                'template_name' => $template_name_sms,
                'parameters' => $parameter_values
            );
        } else {
            send_smstext(
                $final_country_code,
                $final_phone_number,
                $template_name_sms,
                $shop,
                $customer_email,
                $customer_full_name,
                $customer_id,
                $customer_full_name,
                $notification_type
            );

            $response = array(
                'success' => true,
                'message' => 'Plain text SMS sent successfully',
                'template_name' => $template_name_sms
            );
        }
    } else {
        // SMS is disabled - return response without sending SMS
        $response = array(
            'success' => true,
            'message' => 'SMS is disabled for this template, skipping SMS',
            'sms_enabled' => false,
            'whatsapp_enabled' => $whatsapp_enabled
        );
    }
    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(array('success' => false, 'message' => $e->getMessage()));
}

$whatsapp_data_from_db = array();
if (isset($row['whatsapp']) && !empty($row['whatsapp'])) {
    $whatsapp_data_from_db = json_decode($row['whatsapp'], true);
    if (!is_array($whatsapp_data_from_db)) {
        $whatsapp_data_from_db = array();
    }
}

$whatsapp_template_name = isset($whatsapp_data_from_db['template_name']) ? $whatsapp_data_from_db['template_name'] : '';


if ($whatsapp_enabled == 1 && !empty($whatsapp_template_name)) {
    error_log("Processing WhatsApp for customer account invite test - Template: $whatsapp_template_name");

    $whatsapp_api_config = array(
        // 'api_domain' => 'https://your-api-domain.com', 
        // 'channel_id' => 'your_channel_id',
        // 'api_key' => 'your_api_key',
        'log_file' => 'whatsapp_log.txt'
    );

    $whatsapp_table2 = $prefix . "shopify_sms_notification_App_Log_Details";

    $replacement_map = array(
        'email_id' => $customer_email,
        'customer_fname' => $customer_fname,
        'customer_lname' => $customer_lname,
        'customer_full_name' => $customer_full_name,
        'phone_number' => $final_phone_number,
        'country_code' => $final_country_code,
        'customer_id' => $customer_id,
        'customer_state' => $customer_state,
        'customer_note' => $customer_note,
        'customer_verified_email' => $customer_verified_email,
        'default_address' => $default_address_string,
        'account_invite_link' => $account_invite_link
    );

    $processed_headers = array();
    $header_variables = isset($whatsapp_data_from_db['variable_headers']) ? $whatsapp_data_from_db['variable_headers'] : array();

    if (!empty($header_variables)) {
        foreach ($header_variables as $header_var) {
            $processed_value = wa_replace_placeholders($header_var, $replacement_map);
            $processed_headers[] = $processed_value;
            error_log("WhatsApp Header variable: '$header_var' -> '$processed_value'");
        }
    }

    $processed_body = array();
    $body_variables = isset($whatsapp_data_from_db['variable_body']) ? $whatsapp_data_from_db['variable_body'] : array();

    if (!empty($body_variables)) {
        foreach ($body_variables as $body_var) {
            $processed_value = wa_replace_placeholders($body_var, $replacement_map);
            $processed_body[] = $processed_value;
            error_log("WhatsApp Body variable: '$body_var' -> '$processed_value'");
        }
    }

    $whatsapp_media_url = isset($row['media_url']) ? $row['media_url'] : '';
    $whatsapp_media_source = isset($row['media_source']) ? $row['media_source'] : '';
    $whatsapp_media_type = isset($row['media_type']) ? $row['media_type'] : 'text';

    if (!empty($whatsapp_media_url)) {
        $whatsapp_media_url = wa_replace_placeholders($whatsapp_media_url, $replacement_map);
        error_log("WhatsApp Media URL after replacement: $whatsapp_media_url");
    }
    $buttons = array();
    $cta_urls = array();
    if (isset($row['cta_url']) && !empty($row['cta_url'])) {
        $cta_urls = json_decode($row['cta_url'], true);
        if (!is_array($cta_urls)) {
            $cta_urls = array();
        }
    }
    $btn1_type = isset($row['button_type']) ? $row['button_type'] : '';
    $btn1_text = isset($row['button_text1_type']) ? trim($row['button_text1_type']) : '';
    $btn1_url = isset($cta_urls['button1']) ? $cta_urls['button1'] : '';

    if ($btn1_type !== 'none' && !empty($btn1_text)) {
        $btn1_text = wa_replace_placeholders($btn1_text, $replacement_map);
        $btn1_url = wa_replace_placeholders($btn1_url, $replacement_map);

        $buttons[] = array(
            'sub_type' => ($btn1_type === 'cta') ? 'url' : 'quick_reply',
            'index' => '0',
            'value' => ($btn1_type === 'cta') ? $btn1_url : $btn1_text,
            'button_text' => $btn1_text
        );
        error_log("WhatsApp Button 1: type=$btn1_type, text=$btn1_text");
    }

    $btn2_type = isset($row['button_type2']) ? $row['button_type2'] : '';
    $btn2_text = isset($row['button_text2_type']) ? trim($row['button_text2_type']) : '';
    $btn2_url = isset($cta_urls['button2']) ? $cta_urls['button2'] : '';

    if ($btn2_type !== 'none' && !empty($btn2_text)) {
        $btn2_text = wa_replace_placeholders($btn2_text, $replacement_map);
        $btn2_url = wa_replace_placeholders($btn2_url, $replacement_map);

        $buttons[] = array(
            'sub_type' => ($btn2_type === 'cta') ? 'url' : 'quick_reply',
            'index' => '1',
            'value' => ($btn2_type === 'cta') ? $btn2_url : $btn2_text,
            'button_text' => $btn2_text
        );
        error_log("WhatsApp Button 2: type=$btn2_type, text=$btn2_text");
    }
    $btn3_type = isset($row['button_type3']) ? $row['button_type3'] : '';
    $btn3_text = isset($row['button_text3_type']) ? trim($row['button_text3_type']) : '';
    $btn3_url = isset($cta_urls['button3']) ? $cta_urls['button3'] : '';

    if ($btn3_type !== 'none' && !empty($btn3_text)) {
        $btn3_text = wa_replace_placeholders($btn3_text, $replacement_map);
        $btn3_url = wa_replace_placeholders($btn3_url, $replacement_map);

        $buttons[] = array(
            'sub_type' => ($btn3_type === 'cta') ? 'url' : 'quick_reply',
            'index' => '2',
            'value' => ($btn3_type === 'cta') ? $btn3_url : $btn3_text,
            'button_text' => $btn3_text
        );
        error_log("WhatsApp Button 3: type=$btn3_type, text=$btn3_text");
    }
    $wa_phone = "$country_code_input" . "$final_phone_number";
    $whatsapp_config = array_merge($whatsapp_api_config, array(
        'to' => $wa_phone,
        'template_name' => $whatsapp_template_name,
        'language_code' => 'en',
        'media_type' => $whatsapp_media_type,
        'media_url' => $whatsapp_media_url,
        'media_source' => $whatsapp_media_source,
        'variable_headers' => $processed_headers,
        'variable_body' => $processed_body,
        'buttons' => $buttons,
        'shop' => $shop,
        'order_id' => '',
        'order_name' => '',
        'customer_email' => $customer_email,
        'country_code' => $final_country_code,
        'phone_num' => $final_phone_number,
        'notification_type' => $notification_type
    ));

    if (empty($final_phone_number)) {
        error_log("ERROR: Customer phone is empty, cannot send WhatsApp for customer account invite test");
    } else {
        if (function_exists('send_whatsapp_message')) {
            $whatsapp_result = send_whatsapp_message($whatsapp_config, $pdo, $whatsapp_table2);

            if ($whatsapp_result['success']) {
                error_log("WhatsApp sent successfully for customer account invite test to {$final_phone_number}");
            } else {
                error_log("WhatsApp failed for customer account invite test: " . $whatsapp_result['message']);
            }
        } else {
            error_log("WhatsApp function send_whatsapp_message not found");
        }
    }
} else {
    error_log("No WhatsApp template configured for aid=9, skipping WhatsApp send");
}
?>