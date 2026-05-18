<?php
if (!function_exists('set_http_status')) {
    function set_http_status($code)
    {
        if ($code == 200) {
            header('HTTP/1.1 200 OK');
        } elseif ($code == 401) {
            header('HTTP/1.1 401 Unauthorized');
        } elseif ($code == 400) {
            header('HTTP/1.1 400 Bad Request');
        } elseif ($code == 404) {
            header('HTTP/1.1 404 Not Found');
        } elseif ($code == 500) {
            header('HTTP/1.1 500 Internal Server Error');
        } else {
            header('HTTP/1.1 ' . (int) $code);
        }
    }
}
require_once '../config/db.php';
require_once __DIR__ . '/../app_config.php';
include '../accurate_country_code.php';
include './../send_sms_api.php';
include '../send_whatsapp_message_api.php';
include '../dynmc_prod_img.php';
$data = file_get_contents("php://input");
//file_put_contents("$logFile", date('Y-m-d H:i:s') . "\n" . $data . "\n\n", FILE_APPEND);

$customer = json_decode($data);

if (!$customer) {
    file_put_contents("$logFile", "ERROR: Failed to decode JSON\n\n", FILE_APPEND);
    set_http_status(200);
    exit;
}

$shop = isset($_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN']) ? $_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] : '';

if (!$shop) {
    file_put_contents("$logFile", "ERROR: No shop domain in header\n\n", FILE_APPEND);
    set_http_status(200);
    exit;
}

$customerLogId = isset($customer->id) ? $customer->id : 'N/A';
file_put_contents("$logFile", "Shop: $shop | Customer ID: " . $customerLogId . "\n\n", FILE_APPEND);

$pdo = getDatabaseConnection();
$prefix = $app_prefix;
$tables = $prefix . "shopify_sms_notification_app";
$stmt = $pdo->prepare("SELECT shop, session_access_token AS oauth_token FROM $tables WHERE shop = :shop");
$stmt->execute(array(':shop' => $shop));
$shopRow = $stmt->fetch();

if (!$shopRow) {
    file_put_contents("$logFile", "ERROR: Shop not found in database: $shop\n\n", FILE_APPEND);
    set_http_status(200);
    exit;
}

$oauth_token = $shopRow['oauth_token'];

$email_id = isset($customer->email) ? $customer->email : '';
$customer_fname = isset($customer->first_name) ? $customer->first_name : '';
$customer_lname = isset($customer->last_name) ? $customer->last_name : '';
$customer_full_name = trim($customer_fname . ' ' . $customer_lname);
$phone_number = isset($customer->phone) ? $customer->phone : '';

$default_address = isset($customer->default_address) ? $customer->default_address : null;
if ($default_address && isset($default_address->country_code)) {
    $country_code = strtoupper($default_address->country_code);
} else {
    $country_code = 'IN';
}

if ($country_code && $phone_number) {
    $final_arr = getCountryCode_and_phone_number($country_code, $phone_number);
    $country_code = $final_arr['country_code'];
    $phone_number = $final_arr['phone_number'];
}

file_put_contents("$logFile", "========== CUSTOMER UPDATE SMS ==========\n", FILE_APPEND);
file_put_contents("$logFile", "Customer: $customer_full_name\n", FILE_APPEND);
file_put_contents("$logFile", "Phone: $phone_number\n", FILE_APPEND);
file_put_contents("$logFile", "Country Code: $country_code\n", FILE_APPEND);
file_put_contents("$logFile", "Email: $email_id\n", FILE_APPEND);
file_put_contents("$logFile", "========================================\n\n", FILE_APPEND);

$prefix = $app_prefix;
$table = $prefix . "shopify_sms_notification_App_Email_Notification";
$stmt = $pdo->prepare("SELECT * FROM $table WHERE aid = 9 AND shop = :shop");
$stmt->execute(array(':shop' => $shop));
$row = $stmt->fetch();

if ($row) {
    $sms_enabled = isset($row['sms_enabled']) ? (int) $row['sms_enabled'] : 0;
    if ($sms_enabled == 1) {

        $sms_text = $row['sms'];
        $template_name_sms = $row['template_name'];
        $whatsapp_text = $row['whatsapp'];
        $media_type = isset($row['media_type']) ? $row['media_type'] : '';
        $button_type = isset($row['button_type']) ? $row['button_type'] : '';
        $media_url = isset($row['media_url']) ? $row['media_url'] : '';
        $media_source_prod = isset($row['media_source']) ? $row['media_source'] : '';
        $buttonTextRaw = isset($row['button_text1']) ? $row['button_text1'] : '';
        $button_text1 = trim($buttonTextRaw);
        $template_name_sms = $row['template_name'];
        $sms_variables = array();
        $has_parameters = false;

        if (isset($row['sms_variables']) && !empty($row['sms_variables'])) {
            $sms_variables = json_decode($row['sms_variables'], true);
            if (!is_array($sms_variables)) {
                $sms_variables = array();
            }

            if (count($sms_variables) > 0) {
                $has_parameters = true;
                file_put_contents("$logFile", "SMS Variables loaded (has parameters): " . print_r($sms_variables, true) . "\n", FILE_APPEND);
            } else {
                file_put_contents("$logFile", "SMS Variables is empty array\n", FILE_APPEND);
            }
        } else {
            file_put_contents("$logFile", "SMS Variables is NULL or empty\n", FILE_APPEND);
        }

        $replacementMap = array(
            "{{ email_id }}" => $email_id,
            "{{ country_code }}" => $country_code,
            "{{ customer_full_name }}" => $customer_full_name,
            "{{ phone_number }}" => $phone_number,
            "{{ customer_fname }}" => $customer_fname,
            "{{ customer_lname }}" => $customer_lname,
            "{{ customer_id }}" => isset($customer->id) ? $customer->id : '',
            "{{ customer_created_at }}" => isset($customer->created_at) ? $customer->created_at : '',
            "{{ customer_updated_at }}" => isset($customer->updated_at) ? $customer->updated_at : '',
            "{{ customer_orders_count }}" => isset($customer->orders_count) ? $customer->orders_count : '',
            "{{ customer_total_spent }}" => isset($customer->total_spent) ? $customer->total_spent : '',
            "{{ customer_tax_exempt }}" => isset($customer->tax_exempt) ? ($customer->tax_exempt ? 'Yes' : 'No') : ''
        );

        if ($has_parameters) {
            file_put_contents("$logFile", "Using TEMPLATE-BASED SMS with parameters\n", FILE_APPEND);

            // Process custom variables and add to replacement map
            foreach ($sms_variables as $key => $value) {
                $placeholder = "{{ " . trim($key) . " }}";

                // Process the value to replace any placeholders it might contain
                $processed_value = $value;

                // First replace standard placeholders
                foreach ($replacementMap as $ph => $val) {
                    if (strpos($processed_value, $ph) !== false && $val !== null) {
                        $processed_value = str_replace($ph, $val, $processed_value);
                    }
                }

                // Process any nested custom variables
                $recursion_count = 0;
                $max_recursion = 10;
                while (strpos($processed_value, '{{') !== false && $recursion_count < $max_recursion) {
                    foreach ($replacementMap as $ph => $val) {
                        if (strpos($processed_value, $ph) !== false && $val !== null) {
                            $processed_value = str_replace($ph, $val, $processed_value);
                        }
                    }
                    $recursion_count++;
                }

                $replacementMap[$placeholder] = $processed_value;
                file_put_contents("$logFile", "Added custom variable: $placeholder => $processed_value\n", FILE_APPEND);
            }

            $processed_sms_text = $sms_text;

            foreach ($replacementMap as $placeholder => $value) {
                if ($value !== null && strpos($processed_sms_text, $placeholder) !== false) {
                    $processed_sms_text = str_replace($placeholder, $value, $processed_sms_text);
                }
            }
            foreach ($sms_variables as $key => $value) {
                $placeholder = "{{ " . trim($key) . " }}";
                if (isset($replacementMap[$placeholder]) && strpos($processed_sms_text, $placeholder) !== false) {
                    $processed_sms_text = str_replace($placeholder, $replacementMap[$placeholder], $processed_sms_text);
                }
            }
            $processed_whatsapp_text = $whatsapp_text;
            foreach ($replacementMap as $placeholder => $value) {
                if ($value !== null && strpos($processed_whatsapp_text, $placeholder) !== false) {
                    $processed_whatsapp_text = str_replace($placeholder, $value, $processed_whatsapp_text);
                }
            }
            foreach ($sms_variables as $key => $value) {
                $placeholder = "{{ " . trim($key) . " }}";
                if (isset($replacementMap[$placeholder]) && strpos($processed_whatsapp_text, $placeholder) !== false) {
                    $processed_whatsapp_text = str_replace($placeholder, $replacementMap[$placeholder], $processed_whatsapp_text);
                }
            }

            file_put_contents("$logFile", "Original SMS Text: " . $sms_text . "\n", FILE_APPEND);
            file_put_contents("$logFile", "Processed SMS Text: " . $processed_sms_text . "\n", FILE_APPEND);
            file_put_contents("$logFile", "Processed WhatsApp Text: " . $processed_whatsapp_text . "\n", FILE_APPEND);

            $parameter_values = array();
            foreach ($sms_variables as $key => $value) {
                $placeholder = "{{ " . trim($key) . " }}";
                if (isset($replacementMap[$placeholder])) {
                    $parameter_values[$key] = $replacementMap[$placeholder];
                } else {
                    $parameter_values[$key] = $value;
                }
            }

            $template_id = $template_name_sms;

            file_put_contents("$logFile", "Parameter Values for template: " . json_encode($parameter_values) . "\n", FILE_APPEND);
            file_put_contents("$logFile", "Template ID: " . $template_id . "\n", FILE_APPEND);


            foreach ($replacementMap as $placeholder => $value) {
                if ($value !== null) {
                    if (strpos($button_text1, $placeholder) !== false) {
                        $button_text1 = str_replace($placeholder, $value, $button_text1);
                    }
                    if (strpos($media_url, $placeholder) !== false) {
                        $media_url = str_replace($placeholder, $value, $media_url);
                    }
                }
            }

            if ($media_source_prod == 'dynamic' && $media_type == 'image') {
                file_put_contents("$logFile", "INFO: Dynamic product image not applicable for customer update webhook\n", FILE_APPEND);
            }
            if (empty($phone_number)) {
                file_put_contents("$logFile", "ERROR: Customer phone is empty, cannot send SMS for customer update\n", FILE_APPEND);
            } elseif (empty($template_name_sms)) {
                file_put_contents("$logFile", "ERROR: Template ID is empty, cannot send template SMS for customer update\n", FILE_APPEND);
            } else {
                if (function_exists('send_smstext_with_parameters')) {
                    send_smstext_with_parameters(
                        $country_code,
                        $phone_number,
                        $template_name_sms,
                        $shop,
                        $email_id,
                        $customer_full_name,
                        $parameter_values,
                        $customer_full_name,
                        '',
                        'Customer Account Invite'
                    );
                    file_put_contents("$logFile", "Template SMS sent successfully for customer update to {$phone_number}\n", FILE_APPEND);
                    file_put_contents("$logFile", "SMS Text with replacements: {$processed_sms_text}\n", FILE_APPEND);
                } else {
                    send_smstext($country_code, $phone_number, $template_name_sms, $shop, $email_id, $customer_full_name, '', '', "Customer Account Invite");
                    file_put_contents("$logFile", "Template SMS (fallback) sent successfully for customer update to {$phone_number}\n", FILE_APPEND);
                }
            }

            if (!empty($phone_number) && !empty($processed_whatsapp_text) && function_exists('send_whatsapp_text')) {
                if (function_exists('send_whatsapp_text_Plus_button')) {
                    send_whatsapp_text_Plus_button($country_code, $phone_number, $processed_whatsapp_text, $shop, $email_id, $customer_full_name, '', '', 'Customer Update', $media_type, $button_type, $media_url, $button_text1);
                    file_put_contents("$logFile", "WhatsApp with button sent successfully\n", FILE_APPEND);
                } else {
                    send_whatsapp_text($country_code, $phone_number, $processed_whatsapp_text, $shop, $email_id, $customer_full_name, '', '', 'Customer Update');
                    file_put_contents("$logFile", "WhatsApp sent successfully\n", FILE_APPEND);
                }
            }

            file_put_contents("$logFile", "FINAL SMS:\n$processed_sms_text\n\nFINAL WA:\n$processed_whatsapp_text\n\n", FILE_APPEND);

        } else {
            file_put_contents("$logFile", "Using PLAIN TEXT SMS (no parameters)\n", FILE_APPEND);

            $searchVal = array(
                "{{ email_id }}",
                "{{ country_code }}",
                "{{ customer_full_name }}",
                "{{ phone_number }}",
                "{{ customer_fname }}",
                "{{ customer_lname }}",
                "{{ customer_id }}",
                "{{ customer_created_at }}",
                "{{ customer_updated_at }}",
                "{{ customer_orders_count }}",
                "{{ customer_total_spent }}",
                "{{ customer_tax_exempt }}"
            );

            $replaceVal = array(
                $email_id,
                $country_code,
                $customer_full_name,
                $phone_number,
                $customer_fname,
                $customer_lname,
                isset($customer->id) ? $customer->id : '',
                isset($customer->created_at) ? $customer->created_at : '',
                isset($customer->updated_at) ? $customer->updated_at : '',
                isset($customer->orders_count) ? $customer->orders_count : '',
                isset($customer->total_spent) ? $customer->total_spent : '',
                isset($customer->tax_exempt) ? ($customer->tax_exempt ? 'Yes' : 'No') : ''
            );

            $final_sms = str_replace($searchVal, $replaceVal, $sms_text);
            $final_whatsapp = str_replace($searchVal, $replaceVal, $whatsapp_text);

            if (empty($final_sms) || empty($sms_text)) {
                $final_sms = "Hello {$customer_full_name}! Your profile has been updated successfully.";
                file_put_contents("$logFile", "WARNING: Using fallback SMS text for customer update\n", FILE_APPEND);
            }

            $searchReplaceMap = array(
                '{{ email_id }}' => $email_id,
                '{{ country_code }}' => $country_code,
                '{{ customer_full_name }}' => $customer_full_name,
                '{{ phone_number }}' => $phone_number,
                '{{ customer_fname }}' => $customer_fname,
                '{{ customer_lname }}' => $customer_lname,
                '{{ customer_id }}' => isset($customer->id) ? $customer->id : '',
                '{{ customer_created_at }}' => isset($customer->created_at) ? $customer->created_at : '',
                '{{ customer_updated_at }}' => isset($customer->updated_at) ? $customer->updated_at : '',
                '{{ customer_orders_count }}' => isset($customer->orders_count) ? $customer->orders_count : '',
                '{{ customer_total_spent }}' => isset($customer->total_spent) ? $customer->total_spent : '',
                '{{ customer_tax_exempt }}' => isset($customer->tax_exempt) ? ($customer->tax_exempt ? 'Yes' : 'No') : ''
            );

            foreach ($searchReplaceMap as $placeholder => $value) {
                if (!empty($value)) {
                    if (!empty($button_text1) && strpos($button_text1, $placeholder) !== false) {
                        $button_text1 = str_replace($placeholder, $value, $button_text1);
                    }
                    if (!empty($media_url) && strpos($media_url, $placeholder) !== false) {
                        $media_url = str_replace($placeholder, $value, $media_url);
                    }
                }
            }

            file_put_contents("$logFile", "FINAL SMS:\n$final_sms\n\nFINAL WA:\n$final_whatsapp\n\n", FILE_APPEND);

            if (empty($phone_number)) {
                file_put_contents("$logFile", "ERROR: Customer phone is empty, cannot send SMS for customer update\n", FILE_APPEND);
            } elseif (empty($final_sms)) {
                file_put_contents("$logFile", "ERROR: SMS text is empty, cannot send SMS for customer update\n", FILE_APPEND);
            } else {
                send_smstext($country_code, $phone_number, $template_name_sms, $shop, $email_id, $customer_full_name, '', '', "Customer Welcome");
                file_put_contents("$logFile", "SMS sent successfully for customer update to {$phone_number}\n", FILE_APPEND);
            }

            // Send WhatsApp if applicable and function exists
            if (!empty($phone_number) && !empty($final_whatsapp) && function_exists('send_whatsapp_text')) {
                if (function_exists('send_whatsapp_text_Plus_button')) {
                    send_whatsapp_text_Plus_button($country_code, $phone_number, $final_whatsapp, $shop, $email_id, $customer_full_name, '', '', 'Customer Update', $media_type, $button_type, $media_url, $button_text1);
                    file_put_contents("$logFile", "WhatsApp with button sent successfully\n", FILE_APPEND);
                } else {
                    send_whatsapp_text($country_code, $phone_number, $final_whatsapp, $shop, $email_id, $customer_full_name, '', '', 'Customer Update');
                    file_put_contents("$logFile", "WhatsApp sent successfully\n", FILE_APPEND);
                }
            }
        }

    }

} else {
    file_put_contents("$logFile", "WARNING: No enabled SMS template found for aid=9 and shop={$shop}\n", FILE_APPEND);
    file_put_contents("$logFile", "ERROR: No matching template found for aid=9\n\n", FILE_APPEND);
}
set_http_status(200);


$whatsapp_data_from_db = array();
if (isset($row['whatsapp']) && !empty($row['whatsapp'])) {
    $whatsapp_data_from_db = json_decode($row['whatsapp'], true);
    if (!is_array($whatsapp_data_from_db)) {
        $whatsapp_data_from_db = array();
    }
}

$whatsapp_template_name = isset($whatsapp_data_from_db['template_name']) ? $whatsapp_data_from_db['template_name'] : '';

// Only proceed if a WhatsApp template is configured
$whatsapp_enabled = isset($row['whatsapp_enabled']) ? (int) $row['whatsapp_enabled'] : 0;
if ($whatsapp_enabled == 1 && !empty($whatsapp_template_name)) {
    file_put_contents("$logFile", "Processing WhatsApp for customer update - Template: $whatsapp_template_name\n", FILE_APPEND);

    // API Configuration - Replace with your actual values
    $whatsapp_api_config = array(
        // 'api_domain' => 'https://your-api-domain.com',
        // 'channel_id' => 'your_channel_id',
        // 'api_key' => 'your_api_key',
        'log_file' => "$logFile"
    );
    $table2 = $prefix . "shopify_sms_notification_App_Log_Details";
    $replacement_map = array(
        'email_id' => $email_id,
        'country_code' => $country_code,
        'customer_full_name' => $customer_full_name,
        'phone_number' => $phone_number,
        'customer_fname' => $customer_fname,
        'customer_lname' => $customer_lname,
        'customer_id' => isset($customer->id) ? $customer->id : '',
        'customer_created_at' => isset($customer->created_at) ? $customer->created_at : '',
        'customer_updated_at' => isset($customer->updated_at) ? $customer->updated_at : '',
        'customer_orders_count' => isset($customer->orders_count) ? $customer->orders_count : '',
        'customer_total_spent' => isset($customer->total_spent) ? $customer->total_spent : '',
        'customer_tax_exempt' => isset($customer->tax_exempt) ? ($customer->tax_exempt ? 'Yes' : 'No') : ''
    );

    $processed_headers = array();
    $header_variables = isset($whatsapp_data_from_db['variable_headers']) ? $whatsapp_data_from_db['variable_headers'] : array();

    if (!empty($header_variables)) {
        foreach ($header_variables as $header_var) {
            $processed_value = wa_replace_placeholders($header_var, $replacement_map);
            $processed_headers[] = $processed_value;
            file_put_contents("$logFile", "Header variable: '$header_var' -> '$processed_value'\n", FILE_APPEND);
        }
    }

    // ========== PROCESS BODY VARIABLES ==========
    $processed_body = array();
    $body_variables = isset($whatsapp_data_from_db['variable_body']) ? $whatsapp_data_from_db['variable_body'] : array();

    if (!empty($body_variables)) {
        foreach ($body_variables as $body_var) {
            $processed_value = wa_replace_placeholders($body_var, $replacement_map);
            $processed_body[] = $processed_value;
            file_put_contents("$logFile", "Body variable: '$body_var' -> '$processed_value'\n", FILE_APPEND);
        }
    }

    // ========== PROCESS MEDIA URL ==========
    $whatsapp_media_url = isset($row['media_url']) ? $row['media_url'] : '';
    $whatsapp_media_source = isset($row['media_source']) ? $row['media_source'] : '';
    $whatsapp_media_type = isset($row['media_type']) ? $row['media_type'] : 'text';

    // Replace placeholders in media URL
    if (!empty($whatsapp_media_url)) {
        $whatsapp_media_url = wa_replace_placeholders($whatsapp_media_url, $replacement_map);
        file_put_contents("$logFile", "Media URL after replacement: $whatsapp_media_url\n", FILE_APPEND);
    }

    // Handle dynamic product image (not applicable for customer update, but keeping structure)
    if ($whatsapp_media_source == 'dynamic' && $whatsapp_media_type == 'image') {
        file_put_contents("$logFile", "INFO: Dynamic product image not applicable for customer update WhatsApp\n", FILE_APPEND);
    }

    // ========== PROCESS BUTTONS ==========
    $buttons = array();

    // Decode CTA URLs
    $cta_urls = array();
    if (isset($row['cta_url']) && !empty($row['cta_url'])) {
        $cta_urls = json_decode($row['cta_url'], true);
        if (!is_array($cta_urls)) {
            $cta_urls = array();
        }
    }

    // Button 1
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
        file_put_contents("$logFile", "WhatsApp Button 1: type=$btn1_type, text=$btn1_text\n", FILE_APPEND);
    }

    // Button 2
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
        file_put_contents("$logFile", "WhatsApp Button 2: type=$btn2_type, text=$btn2_text\n", FILE_APPEND);
    }

    // Button 3
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
        file_put_contents("$logFile", "WhatsApp Button 3: type=$btn3_type, text=$btn3_text\n", FILE_APPEND);
    }

    // ========== BUILD WHATSAPP CONFIG ==========
    $full_phone_with_code = $country_code . $phone_number;
    $whatsapp_config = array_merge($whatsapp_api_config, array(
        'to' => $full_phone_with_code,
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
        'order_name' => $customer_full_name,
        'customer_email' => $email_id,
        'country_code' => $country_code,
        'phone_num' => $phone_number,
        'notification_type' => 'Customer Account Invite'
    ));

    // ========== SEND WHATSAPP MESSAGE ==========
    if (empty($phone_number)) {
        file_put_contents("$logFile", "ERROR: Customer phone is empty, cannot send WhatsApp for customer update: $email_id\n", FILE_APPEND);
    } else {
        $whatsapp_result = send_whatsapp_message($whatsapp_config, $pdo, $table2);

        if ($whatsapp_result['success']) {
            file_put_contents("$logFile", "WhatsApp sent successfully for customer update: $email_id to $phone_number\n", FILE_APPEND);
        } else {
            file_put_contents("$logFile", "WhatsApp failed for customer update: $email_id - " . $whatsapp_result['message'] . "\n", FILE_APPEND);
        }
    }
} else {
    file_put_contents("$logFile", "No WhatsApp template configured for aid=9, skipping WhatsApp send\n", FILE_APPEND);
}
?>