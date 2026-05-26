<?php
require_once '../config/db.php';
include '../accurate_country_code.php';
include '../send_sms_api.php';
include '../send_whatsapp_message_api.php';
require_once '../app_config.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

$notification_type = "Order Edited";
$countryCode = isset($data['country_code']) ? $data['country_code'] : '';
$phone = isset($data['phone']) ? $data['phone'] : '';
$shop = isset($data['shop']) ? $data['shop'] : '';

if (!$countryCode || !$phone) {
  echo json_encode(array('success' => false, 'message' => 'Missing country code or phone'));
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

  $order_details = json_decode('{
  "id": 820982911946154508,
  "admin_graphql_api_id": "gid://shopify/Order/820982911946154508",
  "app_id": null,
  "browser_ip": null,
  "buyer_accepts_marketing": true,
  "cancel_reason": "customer",
  "cancelled_at": "2021-12-31T19:00:00-05:00",
  "cart_token": null,
  "checkout_token": null,
  "client_details": null,
  "closed_at": null,
  "confirmation_number": null,
  "confirmed": false,
  "contact_email": "jon@example.com",
  "created_at": "2021-12-31T19:00:00-05:00",
  "currency": "USD",
  "current_shipping_price_set": {
    "shop_money": {
      "amount": "0.00",
      "currency_code": "USD"
    },
    "presentment_money": {
      "amount": "0.00",
      "currency_code": "USD"
    }
  },
  "current_subtotal_price": "414.95",
  "current_subtotal_price_set": {
    "shop_money": {
      "amount": "414.95",
      "currency_code": "USD"
    },
    "presentment_money": {
      "amount": "414.95",
      "currency_code": "USD"
    }
  },
  "current_total_additional_fees_set": null,
  "current_total_discounts": "0.00",
  "current_total_discounts_set": {
    "shop_money": {
      "amount": "0.00",
      "currency_code": "USD"
    },
    "presentment_money": {
      "amount": "0.00",
      "currency_code": "USD"
    }
  },
  "current_total_duties_set": null,
  "current_total_price": "414.95",
  "current_total_price_set": {
    "shop_money": {
      "amount": "414.95",
      "currency_code": "USD"
    },
    "presentment_money": {
      "amount": "414.95",
      "currency_code": "USD"
    }
  },
  "current_total_tax": "0.00",
  "current_total_tax_set": {
    "shop_money": {
      "amount": "0.00",
      "currency_code": "USD"
    },
    "presentment_money": {
      "amount": "0.00",
      "currency_code": "USD"
    }
  },
  "customer_locale": "en",
  "device_id": null,
  "discount_codes": [],
  "duties_included": false,
  "email": "jon@example.com",
  "estimated_taxes": false,
  "financial_status": "voided",
  "fulfillment_status": null,
  "landing_site": null,
  "landing_site_ref": null,
  "location_id": null,
  "merchant_business_entity_id": "MTU0ODM4MDAwOQ",
  "merchant_of_record_app_id": null,
  "name": "#9999",
  "note": null,
  "note_attributes": [],
  "number": 234,
  "order_number": 1234,
  "order_status_url": "https://jsmith.myshopify.com/548380009/orders/123456abcd/authenticate?key=abcdefg",
  "original_total_additional_fees_set": null,
  "original_total_duties_set": null,
  "payment_gateway_names": ["visa", "bogus"],
  "phone": null,
  "po_number": null,
  "presentment_currency": "USD",
  "processed_at": "2021-12-31T19:00:00-05:00",
  "reference": null,
  "referring_site": null,
  "source_identifier": null,
  "source_name": "web",
  "source_url": null,
  "subtotal_price": "404.95",
  "subtotal_price_set": {
    "shop_money": {
      "amount": "404.95",
      "currency_code": "USD"
    },
    "presentment_money": {
      "amount": "404.95",
      "currency_code": "USD"
    }
  },
  "tags": "tag1, tag2",
  "tax_exempt": false,
  "tax_lines": [],
  "taxes_included": false,
  "test": true,
  "token": "123456abcd",
  "total_cash_rounding_payment_adjustment_set": {
    "shop_money": {
      "amount": "0.00",
      "currency_code": "USD"
    },
    "presentment_money": {
      "amount": "0.00",
      "currency_code": "USD"
    }
  },
  "total_cash_rounding_refund_adjustment_set": {
    "shop_money": {
      "amount": "0.00",
      "currency_code": "USD"
    },
    "presentment_money": {
      "amount": "0.00",
      "currency_code": "USD"
    }
  },
  "total_discounts": "20.00",
  "total_discounts_set": {
    "shop_money": {
      "amount": "20.00",
      "currency_code": "USD"
    },
    "presentment_money": {
      "amount": "20.00",
      "currency_code": "USD"
    }
  },
  "total_line_items_price": "414.95",
  "total_line_items_price_set": {
    "shop_money": {
      "amount": "414.95",
      "currency_code": "USD"
    },
    "presentment_money": {
      "amount": "414.95",
      "currency_code": "USD"
    }
  },
  "total_outstanding": "414.95",
  "total_price": "404.95",
  "total_price_set": {
    "shop_money": {
      "amount": "404.95",
      "currency_code": "USD"
    },
    "presentment_money": {
      "amount": "404.95",
      "currency_code": "USD"
    }
  },
  "total_shipping_price_set": {
    "shop_money": {
      "amount": "10.00",
      "currency_code": "USD"
    },
    "presentment_money": {
      "amount": "10.00",
      "currency_code": "USD"
    }
  },
  "total_tax": "0.00",
  "total_tax_set": {
    "shop_money": {
      "amount": "0.00",
      "currency_code": "USD"
    },
    "presentment_money": {
      "amount": "0.00",
      "currency_code": "USD"
    }
  },
  "total_tip_received": "0.00",
  "total_weight": 0,
  "updated_at": "2021-12-31T19:00:00-05:00",
  "user_id": null,
  "billing_address": {
    "first_name": "Steve",
    "address1": "123 Shipping Street",
    "phone": "555-555-SHIP",
    "city": "Shippington",
    "zip": "40003",
    "province": "Kentucky",
    "country": "United States",
    "last_name": "Shipper",
    "address2": null,
    "company": "Shipping Company",
    "latitude": null,
    "longitude": null,
    "name": "Steve Shipper",
    "country_code": "US",
    "province_code": "KY"
  },
  "customer": {
    "id": 115310627314723954,
    "created_at": null,
    "updated_at": null,
    "first_name": "John",
    "last_name": "Smith",
    "state": "disabled",
    "note": null,
    "verified_email": true,
    "multipass_identifier": null,
    "tax_exempt": false,
    "email": "john@example.com",
    "phone": null,
    "currency": "USD",
    "tax_exemptions": [],
    "admin_graphql_api_id": "gid://shopify/Customer/115310627314723954",
    "default_address": {
      "id": 715243470612851245,
      "customer_id": 115310627314723954,
      "first_name": "John",
      "last_name": "Smith",
      "company": null,
      "address1": "123 Elm St.",
      "address2": null,
      "city": "Ottawa",
      "province": "Ontario",
      "country": "Canada",
      "zip": "K2H7A8",
      "phone": "123-123-1234",
      "name": "John Smith",
      "province_code": "ON",
      "country_code": "CA",
      "country_name": "Canada",
      "default": true
    }
  },
  "discount_applications": [],
  "fulfillments": [],
  "line_items": [
    {
      "id": 487817672276298554,
      "admin_graphql_api_id": "gid://shopify/LineItem/487817672276298554",
      "attributed_staffs": [
        {
          "id": "gid://shopify/StaffMember/902541635",
          "quantity": 1
        }
      ],
      "current_quantity": 1,
      "fulfillable_quantity": 1,
      "fulfillment_service": "manual",
      "fulfillment_status": null,
      "gift_card": false,
      "grams": 100,
      "name": "Aviator sunglasses",
      "price": "89.99",
      "price_set": {
        "shop_money": {
          "amount": "89.99",
          "currency_code": "USD"
        },
        "presentment_money": {
          "amount": "89.99",
          "currency_code": "USD"
        }
      },
      "product_exists": true,
      "product_id": 788032119674292922,
      "properties": [],
      "quantity": 1,
      "requires_shipping": true,
      "sales_line_item_group_id": null,
      "sku": "SKU2006-001",
      "taxable": true,
      "title": "Aviator sunglasses",
      "total_discount": "0.00",
      "total_discount_set": {
        "shop_money": {
          "amount": "0.00",
          "currency_code": "USD"
        },
        "presentment_money": {
          "amount": "0.00",
          "currency_code": "USD"
        }
      },
      "variant_id": null,
      "variant_inventory_management": null,
      "variant_title": null,
      "vendor": null,
      "tax_lines": [],
      "duties": [],
      "discount_allocations": []
    }
  ],
  "payment_terms": null,
  "refunds": [],
  "shipping_address": {
    "first_name": "Steve",
    "address1": "123 Shipping Street",
    "phone": "555-555-SHIP",
    "city": "Shippington",
    "zip": "40003",
    "province": "Kentucky",
    "country": "United States",
    "last_name": "Shipper",
    "address2": null,
    "company": "Shipping Company",
    "latitude": null,
    "longitude": null,
    "name": "Steve Shipper",
    "country_code": "US",
    "province_code": "KY"
  },
  "shipping_lines": [
    {
      "id": 271878346596884015,
      "carrier_identifier": null,
      "code": null,
      "current_discounted_price_set": {
        "shop_money": {
          "amount": "0.00",
          "currency_code": "USD"
        },
        "presentment_money": {
          "amount": "0.00",
          "currency_code": "USD"
        }
      },
      "discounted_price": "0.00",
      "discounted_price_set": {
        "shop_money": {
          "amount": "0.00",
          "currency_code": "USD"
        },
        "presentment_money": {
          "amount": "0.00",
          "currency_code": "USD"
        }
      },
      "is_removed": false,
      "phone": null,
      "price": "10.00",
      "price_set": {
        "shop_money": {
          "amount": "10.00",
          "currency_code": "USD"
        },
        "presentment_money": {
          "amount": "10.00",
          "currency_code": "USD"
        }
      },
      "requested_fulfillment_service_id": null,
      "source": "shopify",
      "title": "Generic Shipping",
      "tax_lines": [],
      "discount_allocations": []
    }
  ],
  "returns": [],
  "line_item_groups": []
}');

  if ($order_details === null) {
    $json_error = '';
    switch (json_last_error()) {
      case JSON_ERROR_NONE:
        $json_error = 'No error';
        break;
      case JSON_ERROR_DEPTH:
        $json_error = 'Maximum stack depth exceeded';
        break;
      case JSON_ERROR_STATE_MISMATCH:
        $json_error = 'Underflow or the modes mismatch';
        break;
      case JSON_ERROR_CTRL_CHAR:
        $json_error = 'Unexpected control character found';
        break;
      case JSON_ERROR_SYNTAX:
        $json_error = 'Syntax error, malformed JSON';
        break;
      case JSON_ERROR_UTF8:
        $json_error = 'Malformed UTF-8 characters, possibly incorrectly encoded';
        break;
      default:
        $json_error = 'Unknown error';
        break;
    }
    echo json_encode(array('success' => false, 'message' => 'Failed to parse order JSON: ' . $json_error));
    exit;
  }

  $shipping_address = '';
  if (isset($order_details->shipping_address)) {
    $addr = $order_details->shipping_address;
    $shipping_address = implode(', ', array_filter(array(
      isset($addr->first_name) ? $addr->first_name : '',
      isset($addr->last_name) ? $addr->last_name : '',
      isset($addr->address1) ? $addr->address1 : '',
      isset($addr->address2) ? $addr->address2 : '',
      isset($addr->city) ? $addr->city : '',
      isset($addr->province) ? $addr->province : '',
      isset($addr->zip) ? $addr->zip : '',
      isset($addr->country) ? $addr->country : ''
    )));
  }

  $billing_address = '';
  if (isset($order_details->billing_address)) {
    $addr = $order_details->billing_address;
    $billing_address = implode(', ', array_filter(array(
      isset($addr->first_name) ? $addr->first_name : '',
      isset($addr->last_name) ? $addr->last_name : '',
      isset($addr->address1) ? $addr->address1 : '',
      isset($addr->address2) ? $addr->address2 : '',
      isset($addr->city) ? $addr->city : '',
      isset($addr->province) ? $addr->province : '',
      isset($addr->zip) ? $addr->zip : '',
      isset($addr->country) ? $addr->country : ''
    )));
  }

  $customer_phone_from_payload = '';
  if (isset($order_details->customer->default_address->phone)) {
    $customer_phone_from_payload = $order_details->customer->default_address->phone;
  }

  $final_phone_for_sms = !empty($phone) ? $phone : $customer_phone_from_payload;

  $country_code_clean = str_replace('+', '', $countryCode);
  if ($country_code_clean && $final_phone_for_sms) {
    $final_arr = getCountryCode_and_phone_number($country_code_clean, $final_phone_for_sms);
    $final_country_code = $final_arr['country_code'];
    $final_phone_number = $final_arr['phone_number'];
  } else {
    $final_country_code = $country_code_clean;
    $final_phone_number = $final_phone_for_sms;
  }

  $order_country_code = '';
  if (isset($order_details->shipping_address->country_code)) {
    $order_country_code = $order_details->shipping_address->country_code;
  } elseif (isset($order_details->billing_address->country_code)) {
    $order_country_code = $order_details->billing_address->country_code;
  } elseif (isset($order_details->customer->default_address->country_code)) {
    $order_country_code = $order_details->customer->default_address->country_code;
  }

  $actual_values = array(
    "{{ order_name }}" => isset($order_details->name) ? $order_details->name : '',
    "{{ order_total_price }}" => isset($order_details->total_price) ? $order_details->total_price : '',
    "{{ customer_fname }}" => isset($order_details->customer->first_name) ? $order_details->customer->first_name : '',
    "{{ customer_lname }}" => isset($order_details->customer->last_name) ? $order_details->customer->last_name : '',
    "{{ customer_email_id }}" => isset($order_details->contact_email) ? $order_details->contact_email : '',
    "{{ country_code }}" => $order_country_code,
    "{{ customer_phone }}" => $final_phone_number,
    "{{ item_name }}" => isset($order_details->line_items[0]->name) ? $order_details->line_items[0]->name : '',
    "{{ customer_full_name }}" => (isset($order_details->customer->first_name) ? $order_details->customer->first_name : '') . ' ' . (isset($order_details->customer->last_name) ? $order_details->customer->last_name : ''),
    "{{ Ad_order_number }}" => isset($order_details->order_number) ? $order_details->order_number : '',
    "{{ Ad_total_discount }}" => isset($order_details->total_discounts) ? $order_details->total_discounts : '',
    "{{ Ad_order_status_url }}" => isset($order_details->order_status_url) ? $order_details->order_status_url : '',
    "{{ Ad_item_price }}" => isset($order_details->line_items[0]->price) ? $order_details->line_items[0]->price : '',
    "{{ Ad_item_quantity }}" => isset($order_details->line_items[0]->quantity) ? $order_details->line_items[0]->quantity : '',
    "{{ Ad_item_sku }}" => isset($order_details->line_items[0]->sku) ? $order_details->line_items[0]->sku : '',
    "{{ Ad_item_vendor }}" => isset($order_details->line_items[0]->vendor) ? $order_details->line_items[0]->vendor : '',
    "{{ Ad_total_weight }}" => isset($order_details->total_weight) ? $order_details->total_weight : '',
    "{{ Ad_shipping_address }}" => $shipping_address,
    "{{ Ad_billing_address }}" => $billing_address
  );

  $aid = 2;
  $table = $prefix . "shopify_sms_notification_App_Email_Notification";

  $stmt = $pdo->prepare("SELECT * FROM $table WHERE aid=:aid AND shop=:shop");
  $stmt->execute(array(':aid' => $aid, ':shop' => $shop));
  $row = $stmt->fetch();

  if (!$row) {
    echo json_encode(array('success' => false, 'message' => 'Template not found for aid=2'));
    exit;
  }

  $template_name_sms = isset($row['template_name']) ? $row['template_name'] : '';
  $sms_text = isset($row['sms']) ? $row['sms'] : '';
  $sms_enabled = isset($row['sms_enabled']) ? (int) $row['sms_enabled'] : 0;
  $whatsapp_enabled = isset($row['whatsapp_enabled']) ? (int) $row['whatsapp_enabled'] : 0;
  $processed_sms_text = str_replace(
    array_keys($actual_values),
    array_values($actual_values),
    $sms_text
  );

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

  // ========== SMS HANDLING ==========
  if ($sms_enabled == 1) {
    if (!empty($parameter_values)) {
      send_smstext_with_parameters(
        $final_country_code,
        $final_phone_number,
        $template_name_sms,
        $shop,
        $actual_values["{{ customer_email_id }}"],
        $actual_values["{{ customer_full_name }}"],
        $parameter_values,
        isset($order_details->id) ? $order_details->id : '',
        isset($order_details->name) ? $order_details->name : '',
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
        $actual_values["{{ customer_email_id }}"],
        $actual_values["{{ customer_full_name }}"],
        isset($order_details->id) ? $order_details->id : '',
        isset($order_details->name) ? $order_details->name : '',
        $notification_type
      );

      $response = array(
        'success' => true,
        'message' => 'Plain text SMS sent successfully',
        'template_name' => $template_name_sms
      );
    }
  } else {
    $response = array(
      'success' => true,
      'message' => 'SMS is disabled for this template, skipping SMS',
      'sms_enabled' => false,
      'whatsapp_enabled' => $whatsapp_enabled
    );
  }

  // Echo SMS response
  echo json_encode($response);

  // ========== WHATSAPP HANDLING (MOVED OUTSIDE THE SMS ENABLED CHECK) ==========
  $whatsapp_data_from_db = array();
  if (isset($row['whatsapp']) && !empty($row['whatsapp'])) {
    $whatsapp_data_from_db = json_decode($row['whatsapp'], true);
    if (!is_array($whatsapp_data_from_db)) {
      $whatsapp_data_from_db = array();
    }
  }

  $whatsapp_template_name = isset($whatsapp_data_from_db['template_name']) ? $whatsapp_data_from_db['template_name'] : '';

  if ($whatsapp_enabled == 1 && !empty($whatsapp_template_name)) {
    error_log("Processing WhatsApp for order update test - Template: $whatsapp_template_name");

    $whatsapp_api_config = array(
      'log_file' => __DIR__ . '/../file/debug_log.txt'
    );

    $whatsapp_table2 = $prefix . "shopify_sms_notification_App_Log_Details";

    $replacement_map = array(
      'order_name' => isset($order_details->name) ? $order_details->name : '',
      'order_total_price' => isset($order_details->total_price) ? $order_details->total_price : '',
      'customer_fname' => isset($order_details->customer->first_name) ? $order_details->customer->first_name : '',
      'customer_lname' => isset($order_details->customer->last_name) ? $order_details->customer->last_name : '',
      'customer_email_id' => isset($order_details->contact_email) ? $order_details->contact_email : '',
      'country_code' => $final_country_code,
      'customer_phone' => $final_phone_number,
      'item_name' => isset($order_details->line_items[0]->name) ? $order_details->line_items[0]->name : '',
      'customer_full_name' => trim((isset($order_details->customer->first_name) ? $order_details->customer->first_name : '') . ' ' . (isset($order_details->customer->last_name) ? $order_details->customer->last_name : '')),
      'Ad_order_number' => isset($order_details->order_number) ? $order_details->order_number : '',
      'Ad_total_discount' => isset($order_details->total_discounts) ? $order_details->total_discounts : '',
      'Ad_order_status_url' => isset($order_details->order_status_url) ? $order_details->order_status_url : '',
      'Ad_item_price' => isset($order_details->line_items[0]->price) ? $order_details->line_items[0]->price : '',
      'Ad_item_quantity' => isset($order_details->line_items[0]->quantity) ? $order_details->line_items[0]->quantity : '',
      'Ad_item_sku' => isset($order_details->line_items[0]->sku) ? $order_details->line_items[0]->sku : '',
      'Ad_item_vendor' => isset($order_details->line_items[0]->vendor) ? $order_details->line_items[0]->vendor : '',
      'Ad_total_weight' => isset($order_details->total_weight) ? $order_details->total_weight : '',
      'Ad_shipping_address' => $shipping_address,
      'Ad_billing_address' => $billing_address,
      'order_id' => isset($order_details->id) ? $order_details->id : ''
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
    $wa_phone = "$country_code_clean" . "$final_phone_number";
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
      'order_id' => isset($order_details->id) ? $order_details->id : '',
      'order_name' => isset($order_details->name) ? $order_details->name : '',
      'customer_email' => isset($order_details->contact_email) ? $order_details->contact_email : '',
      'country_code' => $final_country_code,
      'phone_num' => $final_phone_number,
      'notification_type' => $notification_type
    ));

    if (empty($final_phone_number)) {
      error_log("ERROR: Customer phone is empty, cannot send WhatsApp for order update test");
    } else {
      if (function_exists('send_whatsapp_message')) {
        $whatsapp_result = send_whatsapp_message($whatsapp_config, $pdo, $whatsapp_table2);

        if ($whatsapp_result['success']) {
          error_log("WhatsApp sent successfully for order update test to {$final_phone_number}");
        } else {
          error_log("WhatsApp failed for order update test: " . $whatsapp_result['message']);
        }
      } else {
        error_log("WhatsApp function send_whatsapp_message not found");
      }
    }
  } else {
    error_log("No WhatsApp template configured for aid=2, skipping WhatsApp send");
  }
  // ========== END WHATSAPP HANDLING ==========

} catch (Exception $e) {
  echo json_encode(array('success' => false, 'message' => $e->getMessage()));
}
?>