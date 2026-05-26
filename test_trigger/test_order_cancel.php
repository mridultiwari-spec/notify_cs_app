<?php
require_once '../config/db.php';
include '../accurate_country_code.php';
include '../send_sms_api.php';
require_once '../app_config.php';
include '../send_whatsapp_message_api.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

$notification_type = "Order Cancelled";
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
  "payment_gateway_names": [
    "visa",
    "bogus"
  ],
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
    },
    {
      "id": 789012345678901234,
      "admin_graphql_api_id": "gid://shopify/LineItem/789012345678901234",
      "attributed_staffs": [],
      "current_quantity": 1,
      "fulfillable_quantity": 1,
      "fulfillment_service": "manual",
      "fulfillment_status": null,
      "gift_card": false,
      "grams": 0,
      "name": "Lens Protection Plan (2 Year)",
      "price": "19.99",
      "price_set": {
        "shop_money": {
          "amount": "19.99",
          "currency_code": "USD"
        },
        "presentment_money": {
          "amount": "19.99",
          "currency_code": "USD"
        }
      },
      "product_exists": false,
      "product_id": null,
      "properties": [],
      "quantity": 1,
      "requires_shipping": true,
      "sales_line_item_group_id": 234567890123456789,
      "sku": "LENS-PROTECT-2YR",
      "taxable": true,
      "title": "Lens Protection Plan (2 Year)",
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
    },
    {
      "id": 890123456789012345,
      "admin_graphql_api_id": "gid://shopify/LineItem/890123456789012345",
      "attributed_staffs": [],
      "current_quantity": 1,
      "fulfillable_quantity": 1,
      "fulfillment_service": "manual",
      "fulfillment_status": null,
      "gift_card": false,
      "grams": 0,
      "name": "Premium Leather Case",
      "price": "24.99",
      "price_set": {
        "shop_money": {
          "amount": "24.99",
          "currency_code": "USD"
        },
        "presentment_money": {
          "amount": "24.99",
          "currency_code": "USD"
        }
      },
      "product_exists": false,
      "product_id": null,
      "properties": [],
      "quantity": 1,
      "requires_shipping": true,
      "sales_line_item_group_id": 234567890123456789,
      "sku": "CASE-LEATHER-PREM",
      "taxable": true,
      "title": "Premium Leather Case",
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
    },
    {
      "id": 976318377106520349,
      "admin_graphql_api_id": "gid://shopify/LineItem/976318377106520349",
      "attributed_staffs": [],
      "current_quantity": 1,
      "fulfillable_quantity": 1,
      "fulfillment_service": "manual",
      "fulfillment_status": null,
      "gift_card": false,
      "grams": 1000,
      "name": "Mid-century lounger",
      "price": "159.99",
      "price_set": {
        "shop_money": {
          "amount": "159.99",
          "currency_code": "USD"
        },
        "presentment_money": {
          "amount": "159.99",
          "currency_code": "USD"
        }
      },
      "product_exists": true,
      "product_id": 788032119674292922,
      "properties": [],
      "quantity": 1,
      "requires_shipping": true,
      "sales_line_item_group_id": 142831562,
      "sku": "SKU2006-020",
      "taxable": true,
      "title": "Mid-century lounger",
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
    },
    {
      "id": 315789986012684393,
      "admin_graphql_api_id": "gid://shopify/LineItem/315789986012684393",
      "attributed_staffs": [],
      "current_quantity": 1,
      "fulfillable_quantity": 1,
      "fulfillment_service": "manual",
      "fulfillment_status": null,
      "gift_card": false,
      "grams": 500,
      "name": "Coffee table",
      "price": "119.99",
      "price_set": {
        "shop_money": {
          "amount": "119.99",
          "currency_code": "USD"
        },
        "presentment_money": {
          "amount": "119.99",
          "currency_code": "USD"
        }
      },
      "product_exists": true,
      "product_id": 788032119674292922,
      "properties": [],
      "quantity": 1,
      "requires_shipping": true,
      "sales_line_item_group_id": 142831562,
      "sku": "SKU2006-035",
      "taxable": true,
      "title": "Coffee table",
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

  $order_id = isset($order_details->id) ? $order_details->id : '';
  $order_name = isset($order_details->name) ? $order_details->name : '';
  $order_total_price = isset($order_details->total_price) ? $order_details->total_price : '';
  $total_discount = isset($order_details->total_discounts) ? $order_details->total_discounts : '';
  $total_weight = isset($order_details->total_weight) ? $order_details->total_weight : '';
  $order_cancel_reason = isset($order_details->cancel_reason) ? $order_details->cancel_reason : '';

  $order_status_url_full = isset($order_details->order_status_url) ? $order_details->order_status_url : '';
  $parts = explode('orders/', $order_status_url_full);
  $order_status_url = isset($parts[1]) ? $parts[1] : '';

  $billing = isset($order_details->billing_address) ? $order_details->billing_address : null;
  $shipping = isset($order_details->shipping_address) ? $order_details->shipping_address : null;

  $billing_string = '';
  if ($billing) {
    $billing_string = implode(', ', array_filter(array(
      isset($billing->first_name) ? $billing->first_name : '',
      isset($billing->last_name) ? $billing->last_name : '',
      isset($billing->address1) ? $billing->address1 : '',
      isset($billing->city) ? $billing->city : '',
      isset($billing->province) ? $billing->province : '',
      isset($billing->zip) ? $billing->zip : '',
      isset($billing->country) ? $billing->country : '',
      isset($billing->phone) ? $billing->phone : ''
    )));
  }

  $shipping_string = '';
  if ($shipping) {
    $shipping_string = implode(', ', array_filter(array(
      isset($shipping->first_name) ? $shipping->first_name : '',
      isset($shipping->last_name) ? $shipping->last_name : '',
      isset($shipping->address1) ? $shipping->address1 : '',
      isset($shipping->city) ? $shipping->city : '',
      isset($shipping->province) ? $shipping->province : '',
      isset($shipping->zip) ? $shipping->zip : '',
      isset($shipping->country) ? $shipping->country : '',
      isset($shipping->phone) ? $shipping->phone : ''
    )));
  }

  $customer = isset($order_details->customer) ? $order_details->customer : null;
  $customer_fname = isset($customer->first_name) ? $customer->first_name : '';
  $customer_lname = isset($customer->last_name) ? $customer->last_name : '';
  $customer_full_name = trim($customer_fname . ' ' . $customer_lname);
  $customer_email_id = isset($order_details->contact_email) ? $order_details->contact_email : '';

  $item_name_arr = array();
  $item_price_arr = array();
  $item_quantity_arr = array();
  $item_sku_arr = array();
  $item_vendor_arr = array();

  if (isset($order_details->line_items) && is_array($order_details->line_items)) {
    foreach ($order_details->line_items as $value) {
      $item_name_arr[] = isset($value->name) ? $value->name : '';
      $item_price_arr[] = isset($value->price) ? $value->price : '';
      $item_quantity_arr[] = isset($value->quantity) ? $value->quantity : '';
      $item_sku_arr[] = isset($value->sku) ? $value->sku : '';
      $item_vendor_arr[] = isset($value->vendor) ? $value->vendor : '';
    }
  }

  $item_name = implode(', ', $item_name_arr);
  $item_price = isset($item_price_arr[0]) ? $item_price_arr[0] : '';
  $item_quantity = isset($item_quantity_arr[0]) ? $item_quantity_arr[0] : '';
  $item_sku = isset($item_sku_arr[0]) ? $item_sku_arr[0] : '';
  $item_vendor = isset($item_vendor_arr[0]) ? $item_vendor_arr[0] : '';

  $country_code_clean = str_replace('+', '', $countryCode);
  $customer_phone = $phone;

  if ($country_code_clean && $customer_phone) {
    $final_arr = getCountryCode_and_phone_number($country_code_clean, $customer_phone);
    $final_country_code = $final_arr['country_code'];
    $final_phone_number = $final_arr['phone_number'];
  } else {
    $final_country_code = $country_code_clean;
    $final_phone_number = $customer_phone;
  }

  $order_country_code = '';
  if ($shipping && isset($shipping->country_code)) {
    $order_country_code = $shipping->country_code;
  } elseif ($billing && isset($billing->country_code)) {
    $order_country_code = $billing->country_code;
  } else {
    $order_country_code = $country_code_clean;
  }

  $actual_values = array(
    "{{ order_name }}" => $order_name,
    "{{ Ad_order_number }}" => $order_id,
    "{{ order_total_price }}" => $order_total_price,
    "{{ customer_fname }}" => $customer_fname,
    "{{ customer_lname }}" => $customer_lname,
    "{{ customer_full_name }}" => $customer_full_name,
    "{{ customer_email_id }}" => $customer_email_id,
    "{{ country_code }}" => $order_country_code,
    "{{ customer_phone }}" => $final_phone_number,
    "{{ item_name }}" => $item_name,
    "{{ Ad_item_price }}" => $item_price,
    "{{ Ad_item_quantity }}" => $item_quantity,
    "{{ Ad_item_sku }}" => $item_sku,
    "{{ Ad_item_vendor }}" => $item_vendor,
    "{{ Ad_total_discount }}" => $total_discount,
    "{{ Ad_total_weight }}" => $total_weight,
    "{{ order_cancel_reason }}" => $order_cancel_reason,
    "{{ Ad_order_status_url }}" => $order_status_url,
    "{{ Ad_shipping_address }}" => $shipping_string,
    "{{ Ad_billing_address }}" => $billing_string
  );

  $aid = 3;
  $prefix = $app_prefix;
  $table = $prefix . "shopify_sms_notification_App_Email_Notification";

  $stmt = $pdo->prepare("SELECT * FROM $table WHERE aid=:aid AND shop=:shop");
  $stmt->execute(array(':aid' => $aid, ':shop' => $shop));
  $row = $stmt->fetch();

  if (!$row) {
    echo json_encode(array('success' => false, 'message' => 'Template not found for Order Cancelled (aid=3)'));
    exit;
  }

  $sms_text = isset($row['sms']) ? $row['sms'] : '';
  $template_name_sms = isset($row['template_name']) ? $row['template_name'] : '';

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

  $sms_enabled = isset($row['sms_enabled']) ? (int) $row['sms_enabled'] : 0;
  $whatsapp_enabled = isset($row['whatsapp_enabled']) ? (int) $row['whatsapp_enabled'] : 0;

  // Initialize response array
  $response = array();
  $message_parts = array();
  $overall_success = false;

  // ============ SMS TEMPLATE NOTIFICATION ============
  if ($sms_enabled == 1) {
    if (!empty($parameter_values)) {
      send_smstext_with_parameters(
        $final_country_code,
        $final_phone_number,
        $template_name_sms,
        $shop,
        $customer_email_id,
        $customer_full_name,
        $parameter_values,
        $order_id,
        $order_name,
        $notification_type
      );
      $message_parts[] = 'SMS sent successfully';
      $overall_success = true;
    } else {
      send_smstext(
        $final_country_code,
        $final_phone_number,
        $template_name_sms,
        $shop,
        $customer_email_id,
        $customer_full_name,
        $order_id,
        $order_name,
        $notification_type
      );
      $message_parts[] = 'SMS sent successfully';
      $overall_success = true;
    }
  } else {
    $message_parts[] = 'SMS is disabled';
  }

  // ============ WHATSAPP TEMPLATE NOTIFICATION ============
  $whatsapp_sent = false;

  if ($whatsapp_enabled == 1) {
    $whatsapp_data_from_db = array();
    if (isset($row['whatsapp']) && !empty($row['whatsapp'])) {
      $whatsapp_data_from_db = json_decode($row['whatsapp'], true);
      if (!is_array($whatsapp_data_from_db)) {
        $whatsapp_data_from_db = array();
      }
    }

    $whatsapp_template_name = isset($whatsapp_data_from_db['template_name']) ? $whatsapp_data_from_db['template_name'] : '';

    if (!empty($whatsapp_template_name)) {
      error_log("Processing WhatsApp for order cancelled test - Template: $whatsapp_template_name");

      $whatsapp_api_config = array(
        'log_file' => __DIR__ . '/../file/debug_log.txt'
      );

      $whatsapp_table2 = $prefix . "shopify_sms_notification_App_Log_Details";

      $replacement_map = array(
        'order_name' => $order_name,
        'order_total_price' => $order_total_price,
        'customer_fname' => $customer_fname,
        'customer_lname' => $customer_lname,
        'customer_email_id' => $customer_email_id,
        'country_code' => $final_country_code,
        'customer_phone' => $final_phone_number,
        'item_name' => $item_name,
        'customer_full_name' => $customer_full_name,
        'Ad_order_number' => $order_id,
        'Ad_total_discount' => $total_discount,
        'Ad_order_status_url' => $order_status_url,
        'Ad_item_price' => $item_price,
        'Ad_item_quantity' => $item_quantity,
        'Ad_item_sku' => $item_sku,
        'Ad_item_vendor' => $item_vendor,
        'Ad_total_weight' => $total_weight,
        'Ad_shipping_address' => $shipping_string,
        'Ad_billing_address' => $billing_string,
        'order_id' => $order_id,
        'order_cancel_reason' => $order_cancel_reason
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

      $wa_phone = $final_country_code . $final_phone_number;

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
        'order_id' => $order_id,
        'order_name' => $order_name,
        'customer_email' => $customer_email_id,
        'country_code' => $final_country_code,
        'phone_num' => $final_phone_number,
        'notification_type' => $notification_type
      ));

      if (empty($final_phone_number)) {
        error_log("ERROR: Customer phone is empty, cannot send WhatsApp for order cancelled test");
      } else {
        if (function_exists('send_whatsapp_message')) {
          $whatsapp_result = send_whatsapp_message($whatsapp_config, $pdo, $whatsapp_table2);

          if (isset($whatsapp_result['success']) && $whatsapp_result['success']) {
            error_log("WhatsApp sent successfully for order cancelled test to {$final_phone_number}");
            $message_parts[] = 'WhatsApp sent successfully';
            $whatsapp_sent = true;
            $overall_success = true;
          } else {
            $error_msg = isset($whatsapp_result['message']) ? $whatsapp_result['message'] : 'Unknown error';
            error_log("WhatsApp failed for order cancelled test: " . $error_msg);
            $message_parts[] = 'WhatsApp failed: ' . $error_msg;
          }
        } else {
          error_log("WhatsApp function send_whatsapp_message not found");
          $message_parts[] = 'WhatsApp function not available';
        }
      }
    } else {
      error_log("No WhatsApp template configured for aid=3, skipping WhatsApp send");
      $message_parts[] = 'No WhatsApp template configured';
    }
  } else {
    error_log("WhatsApp is disabled for this template (whatsapp_enabled=$whatsapp_enabled)");
    $message_parts[] = 'WhatsApp is disabled';
  }

  // Build final response
  $response = array(
    'success' => $overall_success,
    'message' => implode(' | ', array_filter($message_parts)),
    'sms_enabled' => ($sms_enabled == 1),
    'whatsapp_enabled' => ($whatsapp_enabled == 1)
  );

  // Add WhatsApp status if it was sent
  if ($whatsapp_sent) {
    $response['whatsapp_sent'] = true;
  }

  // Send single JSON response at the end
  echo json_encode($response);

} catch (Exception $e) {
  echo json_encode(array('success' => false, 'message' => $e->getMessage()));
}
?>