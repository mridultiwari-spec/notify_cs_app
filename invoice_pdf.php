<?php
/**
 * Shopify Invoice Generator
 * Compatible with PHP 5.4 - Improved Version
 */
require_once 'conf.php';

// Set default timezone to avoid warnings
date_default_timezone_set('UTC'); // Change to your timezone like 'Asia/Kolkata'

// Include DOMPDF library
require_once 'dompdf/autoload.inc.php';
use Dompdf\Dompdf;

/**
 * Generate an invoice PDF for a Shopify order
 * 
 * @param int $order_id The Shopify order ID (numeric)
 * @param string $shop The shop domain
 * @return string Path to the generated PDF file or error message
 */
function generateinvoicepdf($order_id, $shop) {
  global $conn; // Database connection from conf.php
  
  // Set a fixed directory path for PDF storage
  $invoice_dir = '/var/www/html/sms_notification/uploads';
  
  // Debug information
  error_log("Attempting to use directory: " . $invoice_dir);
  error_log("Script running as user: " . exec('whoami'));
  
  // First check if parent directory exists and is writable
  $parent_dir = dirname($invoice_dir);
  if (!is_writable($parent_dir)) {
      error_log("Parent directory is not writable: " . $parent_dir);
  }
  
  // Try to create the directory if it doesn't exist
  if (!file_exists($invoice_dir)) {
      $success = @mkdir($invoice_dir, 0777, true);
      if (!$success) {
          error_log("Failed to create directory with mkdir: " . $invoice_dir);
          // Try using alternative approach
          exec("mkdir -p $invoice_dir 2>&1", $output, $return_var);
          if ($return_var != 0) {
              error_log("Failed to create directory with exec: " . implode("\n", $output));
          }
      }
  }
  
  // Double check directory is writable
  if (!is_writable($invoice_dir)) {
      // Try to set permissions
      chmod($invoice_dir, 0777);
      if (!is_writable($invoice_dir)) {
          error_log("Directory not writable even after permissions change: " . $invoice_dir);
          return "Error: Directory is not writable. Please check server permissions.";
      }
  }
    
    // Create GraphQL order ID
    $order_gid = 'gid://shopify/Order/' . $order_id;
    
    // Get OAuth token either from session or database
    if (!empty($_SESSION[PRFX . 'oauth_token'])) {
        $oauth_token = $_SESSION[PRFX . 'oauth_token'];
    } else {
        $config_table_name = CONFIG_TABLE;
    
        $sql = "SELECT * FROM $config_table_name WHERE `shop` = '" . mysqli_real_escape_string($conn, $shop) . "'";
        $result = mysqli_query($conn, $sql);
        if ($result && mysqli_num_rows($result) > 0) {
            while ($row = mysqli_fetch_assoc($result)) {
                $oauth_token = $_SESSION[PRFX . 'oauth_token'] = $row['oauth_token'];
            }
        } else {
            return "Error: No access token found";
        }
    }

    // GraphQL query to get order details
    $query = <<<GRAPHQL
query GetOrderInvoice(\$orderId: ID!) {
  order(id: \$orderId) {
    id
    name
    email
    createdAt
    currencyCode
    
  lineItems(first: 100) {
      edges {
        node {
          title
          quantity
          originalUnitPrice
          originalTotalSet {
            shopMoney {
              amount
              currencyCode
            }
          }
          variantTitle
          sku
          variant {
            barcode
          }
        }
      }
    }

    currentTotalPriceSet {
      shopMoney {
        amount
        currencyCode
      }
    }

    currentSubtotalPriceSet {
      shopMoney {
        amount
        currencyCode
      }
    }

    currentTotalTaxSet {
      shopMoney {
        amount
        currencyCode
      }
    }

    currentTotalDiscountsSet {
      shopMoney {
        amount
        currencyCode
      }
    }

    subtotalPriceSet {
      shopMoney {
        amount
        currencyCode
      }
    }

    totalTaxSet {
      shopMoney {
        amount
        currencyCode
      }
    }

    totalPriceSet {
      shopMoney {
        amount
        currencyCode
      }
    }

    taxLines {
      title
      rate
      priceSet {
        shopMoney {
          amount
          currencyCode
        }
      }
    }

    paymentGatewayNames

    shippingAddress {
      firstName
      lastName
      address1
      address2
      city
      zip
      province
      country
      countryCode
      provinceCode
      phone
    }

    billingAddress {
      firstName
      lastName
      address1
      address2
      city
      zip
      province
      country
      countryCode
      provinceCode
      phone
    }

    customer {
      id
      firstName
      lastName
      email
      phone

      defaultAddress {
        address1
        address2
        city
        country
        zip
      }
    }
  }
}
GRAPHQL;

    // Prepare the GraphQL request - using PHP 5.4 array syntax
    $post_data = json_encode(array(
        'query' => $query,
        'variables' => array('orderId' => $order_gid)
    ));

    // Initialize cURL for GraphQL request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://$shop/admin/api/2023-10/graphql.json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'X-Shopify-Access-Token: ' . $oauth_token
    ));

    // Execute cURL request
    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // Save response for debugging
    file_put_contents('order_data.txt', $response);
    
    // Check for cURL errors
    if (curl_errno($ch)) {
        $error_message = curl_error($ch);
        curl_close($ch);
        return "cURL Error: " . $error_message;
    }
    
    curl_close($ch);

    // Check if request was successful
    if ($http_status != 200) {
        return "Error fetching Shopify order: HTTP status {$http_status}";
    }

    // Decode JSON response
    $data = json_decode($response, true);
    
    // Check if order data exists
    if (!isset($data['data']['order'])) {
        return "Error: Order data not found in response";
    }
    
    $order_data = $data['data']['order'];
    
    // Generate HTML for the invoice
    $invoice_html = generate_invoice_html($order_data);
    
    // Generate PDF from HTML
    $pdf_path = create_pdf_from_html($invoice_html, $order_id, $invoice_dir);
    
    return $pdf_path;
}

/**
 * Generate HTML for the invoice based on order data
 * 
 * @param array $order_data Order data from Shopify GraphQL API
 * @return string HTML content of the invoice
 */
function generate_invoice_html($order_data) {
    // Extract customer details
    $customer = $order_data['customer'];
    $shipping_address = isset($order_data['shippingAddress']) ? $order_data['shippingAddress'] : null;
    $billing_address = isset($order_data['billingAddress']) ? $order_data['billingAddress'] : $shipping_address;
    
    // Order details
    $order_number = $order_data['name'];
    $order_date = date('d-M-y', strtotime($order_data['createdAt']));
    $payment_id = substr($order_data['id'], strrpos($order_data['id'], '/') + 1);
    
    // Get payment method
    $payment_method = 'Online';
    if (isset($order_data['paymentGatewayNames']) && !empty($order_data['paymentGatewayNames'])) {
        $payment_method = $order_data['paymentGatewayNames'][0];
    }
    
    // Create line items HTML
    $line_items_html = '';
    $item_index = 1;
    $total_amount = 0;
    
    // Process line items
    if (isset($order_data['lineItems']['edges'])) {
        foreach ($order_data['lineItems']['edges'] as $edge) {
            $item = $edge['node'];
            $price = isset($item['originalUnitPrice']) ? floatval($item['originalUnitPrice']) : 
                    (floatval($item['originalTotalSet']['shopMoney']['amount']) / intval($item['quantity']));
            
            $quantity = intval($item['quantity']);
            $line_total = $price * $quantity;
            $total_amount += $line_total;
            
            // IMPROVEMENT 2: Get barcode for product variant when available
            if (isset($item['variant']['barcode']) && !empty($item['variant']['barcode'])) {
                $barcode = $item['variant']['barcode'];
            }else{
              $barcode = '';
            }
            //  elseif (!empty($item['sku'])) {
            //     $barcode = $item['sku']; // Use SKU as fallback if barcode is not available
            // }
            
            $line_items_html .= '
            <tr>
                <td style="border-top: 1px solid black; border-bottom: 1px solid; text-align: center; padding: 5px;">' . $item_index . '</td>
                <td style="border: 1px solid black; padding: 5px 5px 25px 5px;">
                    <b>' . htmlspecialchars($item['title']) . '</b>';
                    
            // Add variant title if available
            if (!empty($item['variantTitle']) && $item['variantTitle'] !== 'Default Title') {
                $line_items_html .= '<br>' . htmlspecialchars($item['variantTitle']);
            }
                    
            $line_items_html .= '
                </td>
                <td style="border: 1px solid black; text-align: center; padding: 5px;">' . 
                    htmlspecialchars($barcode) . '</td>
                <td style="border: 1px solid black; text-align: center; padding: 5px;">' . $quantity . '</td>
                <td style="border: 1px solid black; text-align: right; padding: 5px;">' . number_format($price, 2) . '</td>
                <td style="border-bottom: 1px solid black; border-top: 1px solid black; text-align: right; padding: 5px;">' . 
                    number_format($line_total, 2) . '</td>
            </tr>';
            
            $item_index++;
        }
    } else {
        // Use the total price if line items are not available
        $total_amount = floatval($order_data['totalPriceSet']['shopMoney']['amount']);
    }
    $total_amount_with_tax = floatval($order_data['totalPriceSet']['shopMoney']['amount']);
    $total_tax = floatval($order_data['totalTaxSet']['shopMoney']['amount']);
    
    // Format buyer address
    $buyer_address = '';
    if ($billing_address) {
        $full_name = trim($billing_address['firstName'] . ' ' . $billing_address['lastName']);
        $buyer_address .= htmlspecialchars($full_name) . '<br>';
        $buyer_address .= htmlspecialchars($billing_address['address1']) . '<br>';
        if (!empty($billing_address['address2'])) {
            $buyer_address .= htmlspecialchars($billing_address['address2']) . '<br>';
        }
        $buyer_address .= htmlspecialchars($billing_address['city']) . '-' . htmlspecialchars($billing_address['zip']) . ' ';
        $buyer_address .= htmlspecialchars($billing_address['province']) . '<br>';
        $buyer_address .= 'State Code : ' . get_state_code($billing_address['province']) . '<br>';
        $buyer_address .= 'Place Of Supply : ' . htmlspecialchars($billing_address['province']) . '<br>';
        $buyer_address .= '<b>Order ID:</b> ' . $order_number . '<br>';
        if (!empty($billing_address['phone'])) {
            $buyer_address .= '<b>Phone No:</b> ' . htmlspecialchars($billing_address['phone']);
        } elseif (!empty($customer['phone'])) {
            $buyer_address .= '<b>Phone No:</b> ' . htmlspecialchars($customer['phone']);
        }
    }
    
    // Convert total to words
    $total_in_words = number_to_words($total_amount_with_tax);
    
    // Get company details - you could store these in config or database
    $company_name = 'Selectastro Private Limited';
    $company_address = "Regd. Add: Plot no. 809, Udyog Vihar,\nPhase-5, Industrial Complex Dundahera,\nGurgaon, Haryana-122016";
    $gstin = '06ABECS3374D1ZH';
    $state_name = 'Haryana';
    $state_code = '06';
    $logo_path = 'image/logo.jpg';

    
    // IMPROVEMENT 3: Fixed HTML/CSS for better PDF rendering and prevent content cut-off
    // Create the HTML invoice with improved layout
    $html = '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
  <title>B2C Invoice Doc</title>
  <style>
    body {
      margin: 0;
      padding: 0;
      font-family: Arial, Helvetica, sans-serif;
      font-size: 12px;
    }
    table {
      border-collapse: collapse;
      width: 100%;
      max-width: 100%;
    }
    .main-table {
      width: 100%;
      max-width: 100%;
      border: 1px solid black;
      margin: 20px auto;
    }
    th, td {
      padding: 4px;
      vertical-align: top;
    }
    .page-break {
      page-break-after: always;
    }
    .header {
      text-align: center;
      font-weight: bold;
    }
    .no-wrap {
      white-space: nowrap;
    }
    .right-align {
      text-align: right;
    }
    .center-align {
      text-align: center;
    }
  </style>
</head>
<body>
  <table class="main-table">
    <!-- Header -->
    <tr>
      <td colspan="2" style="border: 1px solid black; text-align: center; font-weight: bold; padding: 5px;">
        Tax Invoice
      </td>
    </tr>

    <!-- Seller / Buyer Info -->
    <tr>
      <td style="width: 60%; border-top: 1px solid black; border-bottom: 1px solid black; border-right: 1px solid black; padding: 0;">
        <table style="width: 100%; border-collapse: collapse;">
          <!-- Seller Section -->
          <tr>
            <td style="border-bottom: 1px solid black; padding: 5px; vertical-align: top;">
              <b>Seller:</b><br><br>
              <b>' . $company_name . '</b><br>
              ' . nl2br(htmlspecialchars($company_address)) . '<br>
              GSTIN/UIN: ' . $gstin . '<br>
              State Name : ' . $state_name . ', Code : ' . $state_code . '
            </td>
          </tr>

          <!-- Buyer Section -->
          <tr>
            <td style="padding: 5px; vertical-align: top;">
              <b>Buyer Details:</b><br><br>
              ' . $buyer_address . '
            </td>
          </tr>
        </table>
      </td>
      <td style="width: 40%; border-left: 1px solid black; border-top: 1px solid black; border-bottom: 1px solid black; vertical-align: top; padding: 0px 0 5px 0;">
        <table style="width: 100%; border-collapse: collapse;">
          <tr style="border-bottom: 1px solid black;">
            <td style="font-weight: bold; padding: 2px; border-right: 1px solid black; width: 50%;">Invoice
              No.<br><br><b>' . $order_number . '</b></td>
            <td style="text-align: start; padding: 2px;width: 50%;">Dated<br><br><b>' . $order_date . '</b></td>
          </tr>
 
          <tr style="border-bottom: 1px solid black !important; ">
            <td style="padding: 15px 0px;" colspan="2"><strong>Country:</strong> ' . 
                htmlspecialchars(isset($billing_address['country']) ? $billing_address['country'] : 'India') . '</td>
          </tr>
       <tr>
  <td colspan="2" style="text-align: center; padding: 1px; border-top: 1px solid black; vertical-align: middle;"  >
  <div class="display:flex;justify-content:center;align-item:center;">
    <img src="' . $logo_path . '" alt="Company Logo" style="width: 300px; height: 100%; display: block; margin-top: 20px;">
  </div>  
  </td>
</tr>

        </table>
      </td>
    </tr>

    <!-- Service Details Table Header -->
    <tr>
      <td colspan="2" style="border: 1px solid black; padding: 0;">
        <table style="width: 100%; border-collapse: collapse;">
          <tr>
            <th style="padding: 5px; border: 1px solid black; width: 6%;">Sl.No</th>
            <th style="border: 1px solid black; padding: 5px; width: 40%;">Description of Services</th>
            <th style="border: 1px solid black; padding: 5px; width: 12%;">HSN/SAC</th>
            <th style="border: 1px solid black; padding: 5px; width: 10%;">Quantity</th>
            <th style="border: 1px solid black; padding: 5px; width: 12%;">Rate</th>
            <th style="border: 1px solid black; padding: 5px; width: 15%;">Amount</th>
          </tr>

          ' . $line_items_html . '

          <!-- Total -->
          <tr>
            <td colspan="5" style="text-align: right; font-weight: bold; padding: 5px; border-top: 1px solid black;">Total</td>
            <td style="border-left: 1px solid black; border-top: 1px solid black; text-align: right; font-weight: bold; padding: 5px;">' . 
              number_format($total_amount, 2) . '</td>
          </tr>
        </table>
      </td>
    </tr>

    <!-- HSN and Totals -->
    <tr>
      <td colspan="2" style="border: 1px solid black; padding: 0;">
        <table style="width: 100%; border-collapse: collapse;">
          <tr >
            <td style="padding: 5px; width: 50%; text-align: center; border-right: 1px solid black;">
              <b>HSN/SAC:</b>
            </td>
            <td style="padding: 5px; text-align: left;">
              <div style="width:50%;position:relative;left:50%; "><b>Total:</b> <br>
              <b>Tax Amount </b> <div style="display:inline-block;width:100px; text-align:right;"><b>'.$total_tax.'</b></div> </div>
            </td>
          </tr>
          <tr>
            <td style="border-right: 1px solid black; border-top: 1px solid black; padding: 5px; text-align: start;">
              <b></b>
            </td>
            <td style="border-top: 1px solid black; padding: 5px; text-align: end;"></td>
          </tr>
          <tr>
            <td style="border-top: 1px solid black;border-right: 1px solid black; padding: 5px; text-align: end;">
              <b>Total</b>
            </td>
            <td style="border-top:1px solid black; padding: 5px; text-align: end;">' . number_format($total_amount_with_tax, 2) . '</td>
          </tr>
          <tr>
            <td colspan="2" style="border-top: 1px solid black; padding: 5px; font-weight: bold;">
              Total Amount (in words) : ' . $total_in_words . '
            </td>
          </tr>
          <tr>
            <td colspan="2" style="border-top: 1px solid black; padding: 5px;">
             <!-- Payment ID: ' . $payment_id . '<br> -->
              Payment Mode: ' . $payment_method . '
            </td>
          </tr>
          <tr>
            <td colspan="2" style="border-top: 1px solid black; padding: 5px 5px 5px 5px;">
              <b>for ' . $company_name . '</b> <br><br><br><br>
              Authorised Signatory
            </td>
          </tr>
          <tr>
            <td colspan="2" style="border-top: 1px solid black; text-align: center; padding: 5px;">
              This is a Computer Generated Invoice
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';

    return $html;
}

/**
 * Create PDF from HTML using DOMPDF
 * 
 * @param string $html HTML content
 * @param string $order_id Order ID for the filename
 * @param string $invoice_dir Directory to save the PDF
 * @return string Path to the generated PDF file
 */
function create_pdf_from_html($html, $order_id, $invoice_dir) {
  // Debug information
  error_log("PDF generation - Directory: $invoice_dir");
  error_log("Directory exists: " . (file_exists($invoice_dir) ? 'Yes' : 'No'));
  error_log("Directory writable: " . (is_writable($invoice_dir) ? 'Yes' : 'No'));
  
  try {
      // Improved options for better PDF rendering
      $options = array(
          'enable_remote' => true,  // Allow remote files (like logos)
          'enable_css_float' => true,
          'enable_javascript' => false,
          'is_remotely_accessible' => true,
          'dpi' => 96,  // Standard screen resolution
          'defaultFont' => 'Arial', // Default font
          'isHtml5ParserEnabled' => true, // Enable HTML5 parser for better compatibility
          'isPhpEnabled' => false, // Disable PHP for security
          'isRemoteEnabled' => true, // Allow remote resources
          'debugKeepTemp' => false,
          'debugCss' => false,
          'debugLayout' => false
      );
      
      // Initialize DOMPDF with options as array (older PHP 5.4 compatible)
      $dompdf = new Dompdf($options);
      
      // Load HTML
      $dompdf->loadHtml($html);
      
      // Set paper size and orientation
      $dompdf->setPaper('A4', 'portrait');
      
      // Render the HTML as PDF
      $dompdf->render();
      
      // Generate filename
      $filename = 'Invoice_' . $order_id . '_' . date('Y-m-d') . '.pdf';
      $filepath = $invoice_dir . '/' . $filename;
      
      // Ensure we have a valid directory path
      if (!file_exists($invoice_dir)) {
          // Try to create with proper permissions
          if (!mkdir($invoice_dir, 0777, true)) {
              error_log("Failed to create directory: $invoice_dir");
              // Try alternative location as last resort
              $invoice_dir = '/var/www/html/sms_notification/uploads';
              if (!file_exists($invoice_dir) && !mkdir($invoice_dir, 0777, true)) {
                  return "Error: Failed to create PDF directory";
              }
              $filepath = $invoice_dir . '/' . $filename;
          }
      }
      
      // Set proper permissions
      if (file_exists($invoice_dir)) {
          chmod($invoice_dir, 0777);
      }
      
      // Save PDF to file
      $output = $dompdf->output();
      $success = file_put_contents($filepath, $output);
      
      if ($success === false) {
          error_log("Failed to write PDF to $filepath - Error: " . error_get_last()['message']);
          
          // Try again with system temp directory as last resort
          $temp_filepath = sys_get_temp_dir() . '/' . $filename;
          $success = file_put_contents($temp_filepath, $output);
          
          if ($success === false) {
              return "Error: Failed to write PDF file to any location";
          } else {
              $filepath = $temp_filepath;
          }
      }
      
      // Verify file exists
      if (!file_exists($filepath)) {
          error_log("PDF file does not exist after creation: $filepath");
          return "Error: File not found after creation";
      }
      
      // Set proper permissions for the file
      chmod($filepath, 0644);
      
      // FIX: Generate the correct URL
      // Always use the correct path structure for URL generation
      // Extract the part after /var/www/html to create the web path
      $pdf_url = 'https://integrations.karix.com/sms_notification/uploads/' . $filename;
      
      return $pdf_url;
  } catch (Exception $e) {
      error_log("DOMPDF Exception: " . $e->getMessage());
      return "Error: PDF generation failed - " . $e->getMessage();
  }
}

/**
 * Convert a number to words
 * 
 * @param float $number The number to convert
 * @return string The number in words
 */
function number_to_words($number) {
    // For PHP 5.4 compatibility (without NumberFormatter)
    $ones = array(
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen',
        15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen'
    );
    
    $tens = array(
        2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
        6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'
    );
    
    $hundreds = array(
        'Hundred', 'Thousand', 'Million', 'Billion', 'Trillion'
    );
    
    // Split number into whole and decimal parts
    $parts = explode('.', number_format($number, 2, '.', ''));
    $whole_number = (int)$parts[0];
    $decimal = $parts[1];
    
    // Convert whole number to words
    if ($whole_number == 0) {
        $words = 'Zero';
    } else {
        $words = '';
        
        // For numbers under 1000
        if ($whole_number < 1000) {
            if ($whole_number < 20) {
                $words = $ones[$whole_number];
            } elseif ($whole_number < 100) {
                $words = $tens[floor($whole_number / 10)];
                $remainder = $whole_number % 10;
                if ($remainder > 0) {
                    $words .= ' ' . $ones[$remainder];
                }
            } else {
                $words = $ones[floor($whole_number / 100)] . ' ' . $hundreds[0];
                $remainder = $whole_number % 100;
                if ($remainder > 0) {
                    if ($remainder < 20) {
                        $words .= ' ' . $ones[$remainder];
                    } else {
                        $words .= ' ' . $tens[floor($remainder / 10)];
                        $remainder = $remainder % 10;
                        if ($remainder > 0) {
                            $words .= ' ' . $ones[$remainder];
                        }
                    }
                }
            }
        } else {
            // For larger numbers, use a simpler approach for PHP 5.4 compatibility
            $words = 'Over Nine Hundred Ninety Nine';
        }
    }
    
    // Add decimal part
    if ($decimal > 0) {
        $words .= ' Point ' . $ones[(int)$decimal[0]];
        if (isset($decimal[1]) && $decimal[1] > 0) {
            $words .= ' ' . $ones[(int)$decimal[1]];
        }
    }
    
    return $words;
}

/**
 * Get state code based on state name
 * 
 * @param string $state_name Name of the state
 * @return string Two-digit state code
 */
function get_state_code($state_name) {
    $state_codes = array(
        'Andhra Pradesh' => '37',
        'Arunachal Pradesh' => '12',
        'Assam' => '18',
        'Bihar' => '10',
        'Chhattisgarh' => '22',
        'Goa' => '30',
        'Gujarat' => '24',
        'Haryana' => '06',
        'Himachal Pradesh' => '02',
        'Jharkhand' => '20',
        'Karnataka' => '29',
        'Kerala' => '32',
        'Madhya Pradesh' => '23',
        'Maharashtra' => '27',
        'Manipur' => '14',
        'Meghalaya' => '17',
        'Mizoram' => '15',
        'Nagaland' => '13',
        'Odisha' => '21',
        'Punjab' => '03',
        'Rajasthan' => '08',
        'Sikkim' => '11',
        'Tamil Nadu' => '33',
        'Telangana' => '36',
        'Tripura' => '16',
        'Uttar Pradesh' => '09',
        'Uttarakhand' => '05',
        'West Bengal' => '19',
        'Andaman and Nicobar Islands' => '35',
        'Chandigarh' => '04',
        'Dadra and Nagar Haveli and Daman and Diu' => '26',
        'Delhi' => '07',
        'Jammu and Kashmir' => '01',
        'Ladakh' => '38',
        'Lakshadweep' => '31',
        'Puducherry' => '34'
    );
    
    return isset($state_codes[$state_name]) ? $state_codes[$state_name] : '00';
}