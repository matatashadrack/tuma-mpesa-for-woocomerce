<?php

/**
 * @package Tuma Payments for WooCommerce
 * @author Tuma Payments < support@tuma.co.ke >
 * @version 1.2.0
 *
 * Plugin Name: Tuma Payments for WooCommerce
 * Plugin URI: https://merchant.tuma.co.ke/
 * Description: This plugin extends WordPress and WooCommerce functionality to integrate your online shop with bank accounts to accept and process online payments via M-Pesa.
 * Author: Shadrack Matata < support@tuma.co.ke >
 * Version: 1.2.0
 * Author URI: https://twitter.com/shadrac_matata/
 *
 * Requires at least: 6.7
 * Tested up to: 6.7
 * Requires PHP: 7.4
 *
 * WC requires at least: 8.0.0
 * WC tested up to: 10.4.3
 *
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

define('TUMA_WC_VER', '1.2.0');
if (!defined('TUMA_WC_PLUGIN_FILE')) {
    define('TUMA_WC_PLUGIN_FILE', __FILE__);
}

register_activation_hook(__FILE__, function () {
    set_transient('tuma-wc-activation-notice', true, 5);
    
    if (!function_exists('is_plugin_active')) {
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
    
    if (!is_plugin_active('woocommerce/woocommerce.php')) {
        deactivate_plugins(plugin_basename(__FILE__));

        add_action('admin_notices', function () {
            $class   = 'notice notice-error is-dismissible';
            $message = __('Please Install/Activate WooCommerce for Tuma Payments to work.', 'woocommerce');

            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
        });
        return;
    }

    flush_rewrite_rules();
});

/**
 * Check transient and show notice only once - delete transient immediately after
 */
add_action('admin_notices', function () {
    if (get_transient('tuma-wc-activation-notice')) {
        $class         = 'updated notice is-dismissible';
        $message       = 'Thank you for installing Tuma Payments for WooCommerce! <strong>You are awesome</strong>!';
        $settings_url  = admin_url('admin.php?page=wc-settings&tab=checkout&section=tuma_payments');
        $settings_text = 'Configure Tuma Payments';
        $btn_class     = 'button button-primary';

        printf(
            '<div class="%1$s"><p>%2$s</p><p><a class="%3$s" href="%4$s">%5$s</a></p></div>',
            esc_attr($class),
            esc_html($message),
            esc_attr($btn_class),
            esc_attr($settings_url),
            esc_html($settings_text)
        );

        delete_transient('tuma-wc-activation-notice');
    }
});

// Enqueue scripts and styles
add_action('wp_enqueue_scripts', function () {
    if (is_checkout()) {
        wp_enqueue_style("tuma-wc-styles", plugins_url("assets/styles.css", __FILE__));
        wp_enqueue_script('jquery');
        wp_enqueue_script("tuma-wc-scripts", plugins_url("assets/scripts.js", __FILE__), array("jquery"), false, true);
    }
});

// Declare compatibility with WooCommerce features
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('orders_cache', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Register WooCommerce Blocks support for Tuma Payments
add_action('woocommerce_blocks_loaded', function() {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }
    
    // Include the blocks integration class
    require_once plugin_dir_path(__FILE__) . 'includes/class-tuma-blocks-support.php';
    
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function(\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
            $payment_method_registry->register(new Tuma_Blocks_Support());
        }
    );
});

// Initialize the payment gateway
add_action('plugins_loaded', 'init_tuma_payments_gateway');

function init_tuma_payments_gateway() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    
    if (!function_exists('is_plugin_active')) {
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }

    class WC_Tuma_Payments_Gateway extends WC_Payment_Gateway {
        
        public function __construct() {
            $this->id                 = 'tuma_payments';
            $this->icon               = 'https://tuma.co.ke/assets/images/linkpay.png';
            $this->has_fields         = true;
            $this->method_title       = 'Tuma Payments';
            $this->method_description = 'Accept M-Pesa payments through Tuma Payments API';
            $this->supports           = array('products');

            // Load the settings
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title              = $this->get_option('title');
            $this->description        = $this->get_option('description');
            $this->enabled            = $this->get_option('enabled');
            $this->testmode           = 'yes' === $this->get_option('testmode');
            $this->api_email          = $this->get_option('api_email');
            $this->api_key            = $this->get_option('api_key');
            $this->api_base_url       = 'https://api.tuma.co.ke';

            // POS sync settings
            $this->enable_pos_sync     = 'yes' === $this->get_option('enable_pos_sync');
            $this->enable_product_sync = 'yes' === $this->get_option('enable_product_sync');

            // Initialize gateway settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            
            // Add webhook handlers
            add_action('woocommerce_api_tuma_callback', array($this, 'webhook'));
            add_action('woocommerce_api_tuma_receipt', array($this, 'get_transaction_receipt'));
            add_action('woocommerce_api_tuma_resend', array($this, 'resend_payment_request'));
            add_action('woocommerce_api_tuma_status', array($this, 'get_payment_status'));
            
            // POS sale callback handler
            add_action('woocommerce_api_tuma_pos_callback', array($this, 'pos_sale_callback'));
            
            // Hook thankyou page to show payment status
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
            add_action('woocommerce_admin_field_tuma_test_connection', array($this, 'generate_tuma_test_connection_html'));
            add_action('woocommerce_admin_field_tuma_sync_products_button', array($this, 'generate_tuma_sync_products_button_html'));
            
            // Enqueue scripts on order received page
            add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => 'Enable/Disable',
                    'type'    => 'checkbox',
                    'label'   => 'Enable Tuma Payments',
                    'default' => 'no'
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'M-Pesa via Tuma Payments',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'Payment method description that the customer will see on your checkout.',
                    'default'     => 'Pay securely using M-Pesa through Tuma Payments.',
                ),
                'testmode' => array(
                    'title'       => 'Test mode',
                    'label'       => 'Enable Test Mode',
                    'type'        => 'checkbox',
                    'description' => 'Place the payment gateway in test mode using test API credentials.',
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
                'api_email' => array(
                    'title'       => 'Shop Email',
                    'type'        => 'email',
                    'description' => 'Enter your Shop Email from the Developer section',
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'api_key' => array(
                    'title'       => 'Shop API Key',
                    'type'        => 'password',
                    'description' => 'Enter your Shop API Key from the Developer section',
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'test_connection' => array(
                    'title'       => 'Connection Status',
                    'type'        => 'tuma_test_connection',
                    'description' => 'Test your API credentials to verify connection.',
                ),
                'pos_sync_section' => array(
                    'title'       => 'POS Inventory Sync',
                    'type'        => 'title',
                    'description' => 'Configure integration with Tuma POS inventory system.',
                ),
                'enable_pos_sync' => array(
                    'title'       => 'Enable POS Sync',
                    'type'        => 'checkbox',
                    'label'       => 'Enable POS inventory synchronization',
                    'description' => 'When enabled, orders will be synced with Tuma POS and inventory will be managed through the POS system.',
                    'default'     => 'no',
                    'desc_tip'    => true,
                ),
                'enable_product_sync' => array(
                    'title'       => 'Enable Product Sync',
                    'type'        => 'checkbox',
                    'label'       => 'Automatically sync products from Tuma POS',
                    'description' => 'When enabled, products from your Tuma POS will be automatically synced to WooCommerce hourly.',
                    'default'     => 'no',
                    'desc_tip'    => true,
                ),
                'sync_products_button' => array(
                    'title'       => 'Manual Product Sync',
                    'type'        => 'tuma_sync_products_button',
                    'description' => 'Manually trigger a product sync from Tuma POS.',
                ),
            );
        }

        public function payment_fields() {
            if ($this->description) {
                echo wpautop(wp_kses_post($this->description));
            }
            ?>
            <fieldset id="wc-<?php echo esc_attr($this->id); ?>-form" class='wc-payment-form' style='background:transparent;'>
                <div class='form-row form-row-wide'>
                    <label>M-Pesa Phone Number <span class="required">*</span></label>
                    <input id="tuma_phone" name="tuma_phone" type="tel" placeholder="0712345678 or 254712345678" autocomplete="tel">
                    <small>Enter your M-Pesa registered phone number (07xxxxxxxx, 01xxxxxxxx, or 254xxxxxxxxx)</small>
                </div>
                <div class='clear'></div>
            </fieldset>
            <?php
        }

        public function validate_fields() {
            if (empty($_POST['tuma_phone'])) {
                wc_add_notice('M-Pesa phone number is required!', 'error');
                return false;
            }

            $phone = sanitize_text_field($_POST['tuma_phone']);
            
            // Validate and normalize phone number
            $normalized_phone = $this->normalize_phone_number($phone);
            if (!$normalized_phone) {
                wc_add_notice('Please enter a valid M-Pesa phone number (e.g., 0712345678 or 254712345678)', 'error');
                return false;
            }

            return true;
        }

        /**
         * Normalize phone number to 254 format
         * Accepts: 07xxxxxxxx, 01xxxxxxxx, 254xxxxxxxxx, +254xxxxxxxxx
         * Returns: 254xxxxxxxxx or false if invalid
         */
        private function normalize_phone_number($phone) {
            // Remove any spaces, dashes, or other non-numeric characters except +
            $phone = preg_replace('/[^0-9+]/', '', $phone);
            
            // Remove + prefix if present
            $phone = ltrim($phone, '+');
            
            // Handle different formats
            if (preg_match('/^07[0-9]{8}$/', $phone)) {
                // 07xxxxxxxx -> 254xxxxxxxxx (take last 9 digits and prepend 254)
                return '254' . substr($phone, -9);
            } elseif (preg_match('/^01[0-9]{8}$/', $phone)) {
                // 01xxxxxxxx -> 254xxxxxxxxx (take last 9 digits and prepend 254)
                return '254' . substr($phone, -9);
            } elseif (preg_match('/^254[71][0-9]{8}$/', $phone)) {
                // 254xxxxxxxxx -> already in correct format (must start with 7 or 1 after 254)
                return $phone;
            }
            
            // If none of the above patterns match, try to extract last 9 digits and prepend 254
            if (strlen($phone) >= 9) {
                $last_nine = substr($phone, -9);
                // Validate that the last 9 digits start with 7 or 1 (valid Kenyan mobile prefixes)
                // 7 = Safaricom, 1 = Airtel
                if (preg_match('/^[71][0-9]{8}$/', $last_nine)) {
                    return '254' . $last_nine;
                }
            }
            
            return false; // Invalid phone number
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $phone = $this->normalize_phone_number(sanitize_text_field($_POST['tuma_phone']));

            // Get access token from Tuma API
            $token = $this->get_access_token();
            if (!$token) {
                wc_add_notice('Payment processing failed. Please try again.', 'error');
                return array('result' => 'fail');
            }

            // Check if POS sync is enabled - use Tuma Sales API instead
            if ($this->enable_pos_sync) {
                return $this->process_pos_sale_payment($order, $phone, $token);
            }

            // Standard payment flow (no POS sync)
            // Prepare payment data
            $payment_data = array(
                'amount' => $order->get_total(),
                'phone' => $phone,
                'description' => 'WooCommerce Order #' . $order->get_order_number(),
                'callback_url' => home_url('wc-api/tuma_callback')
            );

            // Make STK push request
            $response = $this->make_stk_request($token, $payment_data);

            if ($response && isset($response['success']) && $response['success']) {
                // Store payment details in order meta (phone is already normalized)
                $order->update_meta_data('_tuma_payment_id', $response['data']['payment_id']);
                $order->update_meta_data('_tuma_merchant_request_id', $response['data']['merchant_request_id']);
                $order->update_meta_data('_tuma_checkout_request_id', $response['data']['checkout_request_id']);
                $order->update_meta_data('_tuma_phone', $phone);
                $order->save();

                // Mark as pending payment
                $order->update_status('pending', __('Awaiting M-Pesa payment confirmation.', 'woocommerce'));

                // Reduce stock levels
                wc_reduce_stock_levels($order_id);

                // Remove cart
                WC()->cart->empty_cart();

                return array(
                    'result'   => 'success',
                    'redirect' => $this->get_return_url($order)
                );
            } else {
                $error_message = isset($response['message']) ? $response['message'] : 'Payment initiation failed';
                wc_add_notice($error_message, 'error');
                return array('result' => 'fail');
            }
        }

        /**
         * Process payment through Tuma POS Sales API
         * This syncs the order with POS inventory
         */
        private function process_pos_sale_payment($order, $phone, $token) {
            $order_id = $order->get_id();
            
            // Build items array with Tuma product IDs
            $items = array();
            $missing_products = array();
            
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if (!$product) continue;
                
                // Get Tuma product ID from product meta (stored during sync)
                $tuma_product_id = $product->get_meta('_tuma_product_id');
                
                // Fallback: try to find by SKU
                if (empty($tuma_product_id)) {
                    $sku = $product->get_sku();
                    if (!empty($sku)) {
                        // Try to get Tuma product ID by SKU
                        $tuma_product_id = $this->get_tuma_product_id_by_sku($token, $sku);
                        if ($tuma_product_id) {
                            // Cache it for future use
                            $product->update_meta_data('_tuma_product_id', $tuma_product_id);
                            $product->save();
                        }
                    }
                }
                
                if (empty($tuma_product_id)) {
                    $missing_products[] = $product->get_name();
                    continue;
                }
                
                $items[] = array(
                    'product_id' => $tuma_product_id,
                    'quantity'   => $item->get_quantity(),
                );
            }
            
            // If no valid items, fall back to standard payment
            if (empty($items)) {
                error_log('Tuma POS: No products with Tuma IDs found. Products missing: ' . implode(', ', $missing_products));
                wc_add_notice('Some products are not synced with POS. Please contact support or try again.', 'error');
                return array('result' => 'fail');
            }
            
            // Prepare sale payload
            $sale_payload = array(
                'items'          => $items,
                'customer_name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'customer_email' => $order->get_billing_email(),
                'customer_phone' => $phone,
                'payment_method' => 'mpesa',
                'callback_url'   => home_url('wc-api/tuma_pos_callback'),
            );
            
            // Make sale request to Tuma POS
            $response = wp_remote_post($this->api_base_url . '/sales', array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ),
                'body'    => json_encode($sale_payload),
                'timeout' => 30,
            ));
            
            if (is_wp_error($response)) {
                error_log('Tuma POS Sale error: ' . $response->get_error_message());
                wc_add_notice('Payment processing failed. Please try again.', 'error');
                return array('result' => 'fail');
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $status_code = wp_remote_retrieve_response_code($response);
            
            error_log('Tuma POS Sale response: ' . wp_remote_retrieve_body($response));
            
            if ($status_code === 200 || $status_code === 201) {
                // Store sale details in order meta
                if (!empty($body['data']['sale_id'])) {
                    $order->update_meta_data('_tuma_sale_id', $body['data']['sale_id']);
                }
                if (!empty($body['data']['merchant_request_id'])) {
                    $order->update_meta_data('_tuma_merchant_request_id', $body['data']['merchant_request_id']);
                }
                if (!empty($body['data']['checkout_request_id'])) {
                    $order->update_meta_data('_tuma_checkout_request_id', $body['data']['checkout_request_id']);
                }
                $order->update_meta_data('_tuma_phone', $phone);
                $order->update_meta_data('_tuma_pos_sync', 'yes');
                $order->save();
                
                // Mark as pending payment
                $order->update_status('pending', __('Awaiting M-Pesa payment confirmation (POS Sync).', 'woocommerce'));
                
                // Note: Stock will be managed by POS, so we don't reduce WC stock here
                // unless product sync is also enabled
                
                // Remove cart
                WC()->cart->empty_cart();
                
                return array(
                    'result'   => 'success',
                    'redirect' => $this->get_return_url($order),
                );
            } else {
                $error_message = isset($body['message']) ? $body['message'] : 'POS sale creation failed';
                error_log('Tuma POS Sale failed: ' . $error_message);
                wc_add_notice($error_message, 'error');
                return array('result' => 'fail');
            }
        }

        /**
         * Get Tuma product ID by SKU
         */
        private function get_tuma_product_id_by_sku($token, $sku) {
            // Search for product by SKU in Tuma API
            $response = wp_remote_get(
                add_query_arg(array('sku' => $sku), $this->api_base_url . '/products'),
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $token,
                    ),
                    'timeout' => 30,
                )
            );
            
            if (is_wp_error($response)) {
                return false;
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (!empty($body['data']['products'])) {
                foreach ($body['data']['products'] as $product) {
                    if ($product['sku'] === $sku) {
                        return $product['id'];
                    }
                }
            }
            
            return false;
        }

        /**
         * Handle POS sale callback from Tuma
         */
        public function pos_sale_callback() {
            $payload = file_get_contents('php://input');
            $data = json_decode($payload, true);
            
            error_log('Tuma POS callback received: ' . $payload);
            
            if (!$data || empty($data['sale_id'])) {
                http_response_code(400);
                exit(json_encode(array('success' => false, 'message' => 'Invalid payload')));
            }
            
            // Find order by sale_id
            $orders = wc_get_orders(array(
                'meta_key'   => '_tuma_sale_id',
                'meta_value' => $data['sale_id'],
                'limit'      => 1,
            ));
            
            // Fallback to merchant_request_id
            if (empty($orders) && !empty($data['merchant_request_id'])) {
                $orders = wc_get_orders(array(
                    'meta_key'   => '_tuma_merchant_request_id',
                    'meta_value' => $data['merchant_request_id'],
                    'limit'      => 1,
                ));
            }
            
            if (empty($orders)) {
                error_log('Tuma POS callback: Order not found for sale_id: ' . $data['sale_id']);
                http_response_code(404);
                exit(json_encode(array('success' => false, 'message' => 'Order not found')));
            }
            
            $order = $orders[0];
            
            // Prevent processing if already completed
            if (in_array($order->get_status(), array('completed', 'processing'))) {
                http_response_code(200);
                exit(json_encode(array('success' => true, 'message' => 'Already processed')));
            }
            
            $result_code = isset($data['result_code']) ? intval($data['result_code']) : -1;
            
            if ($result_code === 0) {
                // Payment successful
                $receipt = isset($data['mpesa_receipt_number']) ? $data['mpesa_receipt_number'] : '';
                $phone = $order->get_meta('_tuma_phone');
                $old_status = $order->get_status();
                
                $order->set_transaction_id($receipt);
                $order->payment_complete($receipt);
                $order->update_status('completed', 'Payment completed via M-Pesa (POS Sync)');
                
                // Add comprehensive order note
                $note = sprintf(
                    'Full MPesa Payment Received (POS Sync) From %s. Receipt Number %s. Sale ID: %s. Order status changed from %s to Completed.',
                    $phone,
                    $receipt,
                    $data['sale_id'],
                    ucfirst(str_replace('-', ' ', $old_status))
                );
                $order->add_order_note($note);
                
                // Store additional callback data
                if (!empty($data['checkout_request_id'])) {
                    $order->update_meta_data('_tuma_checkout_request_id', $data['checkout_request_id']);
                }
                $order->update_meta_data('_tuma_mpesa_receipt', $receipt);
                $order->save();
                
            } else {
                // Payment failed
                $failure_reason = isset($data['failure_reason']) ? $data['failure_reason'] : 
                                  (isset($data['result_desc']) ? $data['result_desc'] : 'Payment failed');
                
                $order->set_transaction_id('fail');
                $order->update_status('failed', 'M-Pesa payment failed (POS Sync): ' . $failure_reason);
                $order->add_order_note(sprintf(
                    'POS Sale payment failed. Sale ID: %s. Reason: %s. Result Code: %d',
                    $data['sale_id'],
                    $failure_reason,
                    $result_code
                ));
                $order->save();
            }
            
            http_response_code(200);
            exit(json_encode(array('success' => true)));
        }
        
        // Override thankyou page to show payment status - like original M-Pesa plugin
        public function thankyou_page($order_id) {
            $order = wc_get_order($order_id);
            
            if (!$order || $order->get_payment_method() !== 'tuma_payments') {
                return;
            }

            $phone = $order->get_meta('_tuma_phone');
            $total = $order->get_total();
            
            // Only show payment status interface if order is pending
            if ($order->get_status() === 'pending') {
                echo '<div class="woocommerce-order" style="margin-top: 20px;">';
                echo '<h2>Complete Your M-Pesa Payment</h2>';
                
                // Payment instructions
                echo '<div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; padding: 15px; margin: 20px 0;">';
                echo '<h3 style="margin-top: 0; color: #28a745;">Payment Instructions</h3>';
                echo '<ol style="margin: 10px 0; padding-left: 20px;">';
                echo '<li><strong>Check your phone:</strong> An M-Pesa STK push has been sent to <strong>' . esc_html($phone) . '</strong></li>';
                echo '<li><strong>Confirm the amount:</strong> KSh ' . number_format($total, 2) . '</li>';
                echo '<li><strong>Enter your M-Pesa PIN</strong> to authorize the payment</li>';
                echo '<li><strong>Wait for confirmation</strong> - Do not close this page</li>';
                echo '</ol>';
                echo '</div>';
                
                echo '<div id="tuma_receipt" style="padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; margin: 20px 0;">Confirming receipt, please wait <span>.</span><span>.</span><span>.</span><span>.</span><span>.</span><span>.</span></div>';
                
                echo '<table id="renitiate-tuma-table" style="margin-top: 20px;">';
                echo '<tr>';
                echo '<td><button type="button" id="renitiate-tuma-button" class="button alt" style="background: #0073aa; color: white; padding: 10px 20px; border: none; border-radius: 3px; cursor: pointer;">Resend STK Push</button></td>';
                echo '</tr>';
                echo '</table>';
                
                // Hidden fields for JavaScript
                echo '<input type="hidden" id="current_order" value="' . esc_attr($order_id) . '" />';
                echo '<input type="hidden" id="return_url" value="' . esc_url($order->get_checkout_order_received_url()) . '" />';
                echo '<input type="hidden" id="payment_method" value="tuma_payments" />';
                
                echo '</div>';
            }
        }

        private function get_access_token() {
            $auth_data = array(
                'email' => $this->api_email,
                'api_key' => $this->api_key
            );

            $response = wp_remote_post($this->api_base_url . '/auth/token', array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode($auth_data),
                'timeout' => 30
            ));

            if (is_wp_error($response)) {
                error_log('Tuma API auth error: ' . $response->get_error_message());
                return false;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (isset($body['success']) && $body['success'] && isset($body['data']['token'])) {
                return $body['data']['token'];
            }

            error_log('Tuma API auth failed: ' . wp_remote_retrieve_body($response));
            return false;
        }

        private function make_stk_request($token, $payment_data) {
            $response = wp_remote_post($this->api_base_url . '/payment/stk-push', array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ),
                'body' => json_encode($payment_data),
                'timeout' => 30
            ));

            if (is_wp_error($response)) {
                error_log('Tuma STK Push error: ' . $response->get_error_message());
                return false;
            }

            return json_decode(wp_remote_retrieve_body($response), true);
        }

        public function webhook() {
            $payload = file_get_contents('php://input');
            $data = json_decode($payload, true);

            if (!$data) {
                http_response_code(400);
                exit('Invalid payload');
            }

            // Log webhook data for debugging
            error_log('Tuma webhook received: ' . $payload);

            // Process the callback using payment_id or merchant_request_id
            $order = null;
            $order_id = null;

            if (isset($data['payment_id'])) {
                $orders = wc_get_orders(array(
                    'meta_key' => '_tuma_payment_id',
                    'meta_value' => $data['payment_id'],
                    'limit' => 1
                ));
                if (!empty($orders)) {
                    $order = $orders[0];
                }
            }
            
            // Fallback to merchant_request_id if payment_id not found
            if (!$order && isset($data['merchant_request_id'])) {
                $orders = wc_get_orders(array(
                    'meta_key' => '_tuma_merchant_request_id',
                    'meta_value' => $data['merchant_request_id'],
                    'limit' => 1
                ));
                if (!empty($orders)) {
                    $order = $orders[0];
                }
            }

            if ($order) {
                // Prevent processing if already completed
                if ($order->get_status() === 'completed') {
                    http_response_code(200);
                    echo 'OK';
                    exit;
                }
                
                $status = isset($data['status']) ? $data['status'] : 'unknown';
                
                switch ($status) {
                    case 'completed':
                        $receipt = isset($data['mpesa_receipt_number']) ? $data['mpesa_receipt_number'] : 'N/A';
                        $phone = isset($data['phone']) ? $data['phone'] : $order->get_meta('_tuma_phone');
                        $old_status = $order->get_status();
                        
                        $order->set_transaction_id($receipt);
                        $order->update_status('completed', 'Payment completed via M-Pesa');
                        $order->payment_complete($receipt);
                        
                        // Add comprehensive order note like original M-Pesa plugin
                        $note = sprintf(
                            'Full MPesa Payment Received From %s. Receipt Number %s Order status changed from %s to Completed.',
                            $phone,
                            $receipt,
                            ucfirst(str_replace('-', ' ', $old_status))
                        );
                        $order->add_order_note($note);
                        $order->save();
                        break;
                        
                    case 'failed':
                        $reason = isset($data['failure_reason']) ? $data['failure_reason'] : 
                                  (isset($data['result_desc']) ? $data['result_desc'] : 'Payment failed');
                        $order->set_transaction_id('fail');
                        $order->update_status('failed', 'M-Pesa payment failed: ' . $reason);
                        $order->add_order_note('Payment failed: ' . $reason);
                        $order->save();
                        break;
                        
                    case 'cancelled':
                        $order->set_transaction_id('fail');
                        $order->update_status('cancelled', 'M-Pesa payment was cancelled by customer');
                        $order->add_order_note('Payment cancelled by customer');
                        $order->save();
                        break;
                        
                    case 'pending':
                        $order->add_order_note('M-Pesa payment is still pending');
                        break;
                        
                    default:
                        error_log('Unknown payment status: ' . $status);
                        break;
                }
            } else {
                error_log('Order not found for webhook data: ' . $payload);
            }

            // Respond with success as per API documentation
            http_response_code(200);
            echo json_encode(array('success' => true, 'message' => 'Callback received'));
            exit;
        }

        public function check_payment_status() {
            $order_id = sanitize_text_field($_GET['order_id']);
            
            if (!$order_id) {
                wp_send_json_error(array('message' => 'Order ID required'));
            }
            
            $order = wc_get_order($order_id);
            if (!$order) {
                wp_send_json_error(array('message' => 'Order not found'));
            }
            
            $status = $order->get_status();
            $transaction_id = $order->get_transaction_id();
            
            wp_send_json_success(array(
                'status' => $status,
                'transaction_id' => $transaction_id,
                'is_completed' => in_array($status, array('completed', 'processing')),
                'is_failed' => in_array($status, array('failed', 'cancelled'))
            ));
        }
        
        // Get transaction receipt like original M-Pesa plugin
        public function get_transaction_receipt() {
            $response = array('receipt' => '');

            if (!empty($_GET['order'])) {
                $order_id = sanitize_text_field($_GET['order']);
                $order    = wc_get_order(esc_attr($order_id));
                $notes    = wc_get_order_notes(array(
                    'post_id' => $order_id,
                    'number'  => 1,
                ));

                $response = array(
                    'receipt'                 => $order->get_transaction_id(),
                    'note'                    => $notes[0],
                    'user_token'              => $order->get_meta('user_token'),
                    'user_token_instructions' => $order->get_meta('user_token_instructions'),
                );
            }

            exit(wp_send_json($response));
        }
        
        // Resend payment request
        public function resend_payment_request() {
            $order_id = sanitize_text_field($_POST['order']);
            $order = wc_get_order($order_id);
            
            if (!$order) {
                wp_send_json_error('Order not found');
            }
            
            $total = $order->get_total();
            $phone = $order->get_meta('_tuma_phone');
            
            if (!$phone) {
                wp_send_json_error('Phone number not found');
            }
            
            // Ensure phone is in normalized format
            $phone = $this->normalize_phone_number($phone);
            if (!$phone) {
                wp_send_json_error('Invalid phone number format');
            }
            
            // Get access token
            $token = $this->get_access_token();
            if (!$token) {
                wp_send_json_error('Authentication failed');
            }
            
            // Check if this is a POS sync order - resend via sales API
            $is_pos_sync = $order->get_meta('_tuma_pos_sync') === 'yes';
            if ($is_pos_sync) {
                $this->resend_pos_sale_request($order, $phone, $token);
                return;
            }
            
            // Standard payment - Make STK push request
            $payment_data = array(
                'amount' => $total,
                'phone' => $phone,
                'description' => 'WooCommerce Order #' . $order->get_order_number() . ' (Resend)',
                'callback_url' => home_url('wc-api/tuma_callback')
            );
            
            $response = $this->make_stk_request($token, $payment_data);
            
            if ($response && isset($response['success']) && $response['success']) {
                // Update payment metadata
                $order->update_meta_data('_tuma_payment_id', $response['data']['payment_id']);
                $order->update_meta_data('_tuma_merchant_request_id', $response['data']['merchant_request_id']);
                $order->update_meta_data('_tuma_checkout_request_id', $response['data']['checkout_request_id']);
                $order->save();
                
                $order->add_order_note(
                    sprintf(__('Tuma STK push resent to %s. Merchant Request ID: %s'), $phone, $response['data']['merchant_request_id'])
                );
                
                wp_send_json_success($response['data']);
            } else {
                $error_message = isset($response['message']) ? $response['message'] : 'Payment request failed';
                wp_send_json_error($error_message);
            }
        }
        
        // Resend POS sale payment request
        private function resend_pos_sale_request($order, $phone, $token) {
            // Build items array with Tuma product IDs
            $items = array();
            
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if (!$product) continue;
                
                $tuma_product_id = $product->get_meta('_tuma_product_id');
                
                if (empty($tuma_product_id)) {
                    $sku = $product->get_sku();
                    if (!empty($sku)) {
                        $tuma_product_id = $this->get_tuma_product_id_by_sku($token, $sku);
                    }
                }
                
                if (empty($tuma_product_id)) continue;
                
                $items[] = array(
                    'product_id' => $tuma_product_id,
                    'quantity'   => $item->get_quantity(),
                );
            }
            
            if (empty($items)) {
                wp_send_json_error('No valid products found for POS sync');
                return;
            }
            
            // Prepare sale payload
            $sale_payload = array(
                'items'          => $items,
                'customer_name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'customer_email' => $order->get_billing_email(),
                'customer_phone' => $phone,
                'payment_method' => 'mpesa',
                'callback_url'   => home_url('wc-api/tuma_pos_callback'),
            );
            
            $response = wp_remote_post($this->api_base_url . '/sales', array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ),
                'body'    => json_encode($sale_payload),
                'timeout' => 30,
            ));
            
            if (is_wp_error($response)) {
                wp_send_json_error('Failed to resend payment request');
                return;
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $status_code = wp_remote_retrieve_response_code($response);
            
            if ($status_code === 200 || $status_code === 201) {
                if (!empty($body['data']['sale_id'])) {
                    $order->update_meta_data('_tuma_sale_id', $body['data']['sale_id']);
                }
                if (!empty($body['data']['merchant_request_id'])) {
                    $order->update_meta_data('_tuma_merchant_request_id', $body['data']['merchant_request_id']);
                }
                if (!empty($body['data']['checkout_request_id'])) {
                    $order->update_meta_data('_tuma_checkout_request_id', $body['data']['checkout_request_id']);
                }
                $order->save();
                
                $order->add_order_note(
                    sprintf(__('Tuma POS sale resent to %s. Sale ID: %s'), $phone, $body['data']['sale_id'] ?? 'N/A')
                );
                
                wp_send_json_success($body['data'] ?? array());
            } else {
                $error_message = isset($body['message']) ? $body['message'] : 'POS sale request failed';
                wp_send_json_error($error_message);
            }
        }

        public function generate_tuma_test_connection_html($key, $data) {
            $field_key = $this->get_field_key($key);
            $defaults  = array(
                'title'             => '',
                'disabled'          => false,
                'class'             => '',
                'css'               => '',
                'placeholder'       => '',
                'type'              => 'text',
                'desc_tip'          => false,
                'description'       => '',
                'custom_attributes' => array(),
            );

            $data = wp_parse_args($data, $defaults);

            ob_start();
            ?>
            <tr valign="top">
                <th scope="row" class="titledesc">
                    <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></label>
                </th>
                <td class="forminp">
                    <div id="tuma-connection-status">
                        <button type="button" id="test-tuma-connection" class="button-secondary">Test Connection</button>
                        <div id="tuma-connection-result" style="margin-top: 10px;"></div>
                    </div>
                    <?php if (!empty($data['description'])) : ?>
                        <p class="description"><?php echo wp_kses_post($data['description']); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#test-tuma-connection').on('click', function() {
                    var button = $(this);
                    var result = $('#tuma-connection-result');
                    var email = $('#woocommerce_tuma_payments_api_email').val();
                    var apiKey = $('#woocommerce_tuma_payments_api_key').val();

                    if (!email || !apiKey) {
                        result.html('<div style="color: red;">Please enter both API Email and API Key before testing.</div>');
                        return;
                    }

                    button.prop('disabled', true).text('Testing...');
                    result.html('<div style="color: #666;">Testing connection...</div>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'tuma_test_connection',
                            email: email,
                            api_key: apiKey,
                            nonce: '<?php echo wp_create_nonce('tuma_test_connection'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                result.html('<div style="color: green; padding: 10px; background: #f0f8f0; border: 1px solid #4CAF50; border-radius: 4px;"><strong>✓ Connection Successful!</strong><br>Shop: ' + response.data.shop_name + '<br>Email: ' + response.data.shop_email + '</div>');
                            } else {
                                result.html('<div style="color: red; padding: 10px; background: #fff0f0; border: 1px solid #f44336; border-radius: 4px;"><strong>✗ Connection Failed</strong><br>' + response.data.message + '</div>');
                            }
                        },
                        error: function() {
                            result.html('<div style="color: red;">Connection test failed. Please try again.</div>');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Test Connection');
                        }
                    });
                });
            });
            </script>
            <?php
            return ob_get_clean();
        }

        public function generate_tuma_sync_products_button_html($key, $data) {
            $field_key = $this->get_field_key($key);
            $defaults  = array(
                'title'             => '',
                'disabled'          => false,
                'class'             => '',
                'css'               => '',
                'placeholder'       => '',
                'type'              => 'text',
                'desc_tip'          => false,
                'description'       => '',
                'custom_attributes' => array(),
            );

            $data = wp_parse_args($data, $defaults);

            ob_start();
            ?>
            <tr valign="top">
                <th scope="row" class="titledesc">
                    <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></label>
                </th>
                <td class="forminp">
                    <div id="tuma-sync-products-status">
                        <button type="button" id="sync-tuma-products" class="button-secondary">Sync Products Now</button>
                        <div id="tuma-sync-result" style="margin-top: 10px;"></div>
                    </div>
                    <?php if (!empty($data['description'])) : ?>
                        <p class="description"><?php echo wp_kses_post($data['description']); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#sync-tuma-products').on('click', function() {
                    var button = $(this);
                    var result = $('#tuma-sync-result');

                    button.prop('disabled', true).text('Syncing...');
                    result.html('<div style="color: #666;">Syncing products from Tuma POS... This may take a few minutes.</div>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'tuma_sync_products',
                            nonce: '<?php echo wp_create_nonce('tuma_sync_products'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                result.html('<div style="color: green; padding: 10px; background: #f0f8f0; border: 1px solid #4CAF50; border-radius: 4px;"><strong>✓ Sync Completed!</strong><br>Products synced: ' + response.data.synced + '<br>Products updated: ' + response.data.updated + '<br>Products created: ' + response.data.created + '</div>');
                            } else {
                                result.html('<div style="color: red; padding: 10px; background: #fff0f0; border: 1px solid #f44336; border-radius: 4px;"><strong>✗ Sync Failed</strong><br>' + response.data.message + '</div>');
                            }
                        },
                        error: function() {
                            result.html('<div style="color: red;">Sync failed. Please try again.</div>');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Sync Products Now');
                        }
                    });
                });
            });
            </script>
            <?php
            return ob_get_clean();
        }

        public function process_admin_options() {
            $saved = parent::process_admin_options();
            
            // Auto-test connection when saving if credentials are provided
            if ($this->get_option('api_email') && $this->get_option('api_key')) {
                $test_result = $this->test_api_connection($this->get_option('api_email'), $this->get_option('api_key'));
                if (!$test_result['success']) {
                    WC_Admin_Settings::add_error('Tuma Payments: ' . $test_result['message']);
                } else {
                    WC_Admin_Settings::add_message('Tuma Payments: Connection verified successfully for shop "' . $test_result['shop_name'] . '"');
                }
            }
            
            return $saved;
        }

        private function test_api_connection($email, $api_key) {
            $auth_data = array(
                'email' => $email,
                'api_key' => $api_key
            );

            $response = wp_remote_post($this->api_base_url . '/auth/token', array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode($auth_data),
                'timeout' => 30
            ));

            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'message' => 'Connection error: ' . $response->get_error_message()
                );
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $status_code = wp_remote_retrieve_response_code($response);

            if ($status_code === 200 && isset($body['success']) && $body['success']) {
                return array(
                    'success' => true,
                    'shop_name' => $body['data']['shop']['name'],
                    'shop_email' => $body['data']['shop']['email']
                );
            } else if ($status_code === 403 && isset($body['error_code']) && $body['error_code'] === 'IPRS_VERIFICATION_REQUIRED') {
                return array(
                    'success' => false,
                    'message' => 'IPRS verification required. Please complete identity verification in your merchant portal.'
                );
            } else {
                return array(
                    'success' => false,
                    'message' => isset($body['message']) ? $body['message'] : 'Invalid credentials'
                );
            }
        }

        public function is_available() {
            // Check if the gateway is enabled
            if ('yes' !== $this->enabled) {
                return false;
            }

            // Check if required settings are configured
            if (empty($this->api_email) || empty($this->api_key)) {
                return false;
            }

            // Check if WooCommerce is active
            if (!class_exists('WooCommerce')) {
                return false;
            }

            // Only check cart on frontend checkout pages
            if (!is_admin() && (is_checkout() || is_checkout_pay_page())) {
                // Check if we have a valid cart with items
                if (WC()->cart && WC()->cart->is_empty()) {
                    return false;
                }

                // Check if the total is greater than 0
                if (WC()->cart && WC()->cart->get_total('') <= 0) {
                    return false;
                }
            }

            return true;
        }

        public function payment_scripts() {
            if (!is_admin() && (!is_checkout() && !is_checkout_pay_page() && !is_order_received_page())) {
                return;
            }

            wp_enqueue_script('tuma-payment-js', plugins_url('assets/payment.js', __FILE__), array('jquery'), TUMA_WC_VER, true);
            wp_enqueue_style('tuma-payment-css', plugins_url('assets/styles.css', __FILE__), array(), TUMA_WC_VER);
            
            // Add receipt URL like original M-Pesa plugin
            wp_add_inline_script('tuma-payment-js', 'var TUMA_RECEIPT_URL = "' . home_url('wc-api/tuma_receipt') . '"', 'before');
            
            // Localize script for AJAX
            wp_localize_script('tuma-payment-js', 'tuma_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('tuma_payment_status'),
                'check_status_url' => home_url('wc-api/tuma_status')
            ));
        }

        // Custom payment page display - like original M-Pesa plugin
        public function receipt_page($order_id) {
            $order = wc_get_order($order_id);
            
            if (!$order) {
                return;
            }

            $phone = $order->get_meta('_tuma_phone');
            $total = $order->get_total();
            
            echo '<div class="woocommerce-order">';
            echo '<h2>Complete Your M-Pesa Payment</h2>';
            
            // Payment instructions
            echo '<div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; padding: 15px; margin: 20px 0;">';
            echo '<h3 style="margin-top: 0; color: #28a745;">Payment Instructions</h3>';
            echo '<ol style="margin: 10px 0; padding-left: 20px;">';
            echo '<li><strong>Check your phone:</strong> An M-Pesa STK push has been sent to <strong>' . esc_html($phone) . '</strong></li>';
            echo '<li><strong>Confirm the amount:</strong> KSh ' . number_format($total, 2) . '</li>';
            echo '<li><strong>Enter your M-Pesa PIN</strong> to authorize the payment</li>';
            echo '<li><strong>Wait for confirmation</strong> - Do not close this page</li>';
            echo '</ol>';
            echo '</div>';
            
            echo '<div id="tuma_receipt" style="padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; margin: 20px 0;">Confirming receipt, please wait <span>.</span><span>.</span><span>.</span><span>.</span><span>.</span><span>.</span></div>';
            
            echo '<table id="renitiate-tuma-table" style="margin-top: 20px;">';
            echo '<tr>';
            echo '<td><button type="button" id="renitiate-tuma-button" class="button alt" style="background: #0073aa; color: white; padding: 10px 20px; border: none; border-radius: 3px; cursor: pointer;">Resend STK Push</button></td>';
            echo '</tr>';
            echo '</table>';
            
            // Hidden fields for JavaScript
            echo '<input type="hidden" id="current_order" value="' . esc_attr($order_id) . '" />';
            echo '<input type="hidden" id="return_url" value="' . esc_url($order->get_checkout_order_received_url()) . '" />';
            echo '<input type="hidden" id="payment_method" value="tuma_payments" />';
            
            echo '</div>';
        }
    }
}

// Add the gateway to WooCommerce
function add_tuma_payments_gateway($gateways) {
    $gateways[] = 'WC_Tuma_Payments_Gateway';
    return $gateways;
}
add_filter('woocommerce_payment_gateways', 'add_tuma_payments_gateway');

// Debug: Log available gateways at checkout
add_action('woocommerce_review_order_before_payment', function() {
    $available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
    error_log('Tuma Debug: Available gateways at checkout: ' . print_r(array_keys($available_gateways), true));
    error_log('Tuma Debug: Cart needs payment: ' . (WC()->cart->needs_payment() ? 'YES' : 'NO'));
    error_log('Tuma Debug: Cart total: ' . WC()->cart->get_total(''));
});

// AJAX handler for connection testing
add_action('wp_ajax_tuma_test_connection', 'handle_tuma_test_connection');

function handle_tuma_test_connection() {
    // Verify nonce for security
    if (!wp_verify_nonce($_POST['nonce'], 'tuma_test_connection')) {
        wp_die('Security check failed');
    }

    $email = sanitize_email($_POST['email']);
    $api_key = sanitize_text_field($_POST['api_key']);

    if (empty($email) || empty($api_key)) {
        wp_send_json_error(array('message' => 'Email and API key are required'));
    }

    // Test the connection
    $auth_data = array(
        'email' => $email,
        'api_key' => $api_key
    );

    $response = wp_remote_post('https://api.tuma.co.ke/auth/token', array(
        'headers' => array(
            'Content-Type' => 'application/json',
        ),
        'body' => json_encode($auth_data),
        'timeout' => 30
    ));

    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => 'Connection error: ' . $response->get_error_message()));
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $status_code = wp_remote_retrieve_response_code($response);

    if ($status_code === 200 && isset($body['success']) && $body['success']) {
        wp_send_json_success(array(
            'shop_name' => $body['data']['shop']['name'],
            'shop_email' => $body['data']['shop']['email']
        ));
    } else if ($status_code === 403 && isset($body['error_code']) && $body['error_code'] === 'IPRS_VERIFICATION_REQUIRED') {
        wp_send_json_error(array('message' => 'IPRS verification required. Please complete identity verification in your merchant portal.'));
    } else {
        wp_send_json_error(array('message' => isset($body['message']) ? $body['message'] : 'Invalid credentials'));
    }
}

// ============================================================================
// PRODUCT SYNC FUNCTIONALITY
// ============================================================================

// AJAX handler for manual product sync
add_action('wp_ajax_tuma_sync_products', 'handle_tuma_sync_products');

function handle_tuma_sync_products() {
    // Verify nonce for security
    if (!wp_verify_nonce($_POST['nonce'], 'tuma_sync_products')) {
        wp_die('Security check failed');
    }
    
    // Check user capabilities
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }
    
    $result = tuma_sync_products_from_pos();
    
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

// Schedule hourly sync if enabled
register_activation_hook(__FILE__, 'tuma_schedule_product_sync');
function tuma_schedule_product_sync() {
    if (!wp_next_scheduled('tuma_hourly_product_sync_event')) {
        wp_schedule_event(time(), 'hourly', 'tuma_hourly_product_sync_event');
    }
}

register_deactivation_hook(__FILE__, 'tuma_clear_product_sync_schedule');
function tuma_clear_product_sync_schedule() {
    wp_clear_scheduled_hook('tuma_hourly_product_sync_event');
}

add_action('tuma_hourly_product_sync_event', 'tuma_scheduled_product_sync');
function tuma_scheduled_product_sync() {
    $settings = get_option('woocommerce_tuma_payments_settings', array());
    
    // Only sync if product sync is enabled
    if (isset($settings['enable_product_sync']) && $settings['enable_product_sync'] === 'yes') {
        tuma_sync_products_from_pos();
    }
}

/**
 * Get Tuma API token using gateway settings
 */
function tuma_get_api_token() {
    $settings = get_option('woocommerce_tuma_payments_settings', array());
    
    $email = isset($settings['api_email']) ? $settings['api_email'] : '';
    $api_key = isset($settings['api_key']) ? $settings['api_key'] : '';
    
    if (empty($email) || empty($api_key)) {
        return false;
    }
    
    // Check for cached token
    $token = get_transient('tuma_api_token');
    if ($token) {
        return $token;
    }
    
    $response = wp_remote_post('https://api.tuma.co.ke/auth/token', array(
        'body' => json_encode(array(
            'email'   => $email,
            'api_key' => $api_key
        )),
        'headers' => array('Content-Type' => 'application/json'),
        'timeout' => 20,
    ));
    
    if (is_wp_error($response)) {
        error_log('Tuma API auth error: ' . $response->get_error_message());
        return false;
    }
    
    $data = json_decode(wp_remote_retrieve_body($response), true);
    
    if (!empty($data['data']['token'])) {
        set_transient('tuma_api_token', $data['data']['token'], 3500);
        return $data['data']['token'];
    }
    
    return false;
}

/**
 * Sync products from Tuma POS to WooCommerce
 */
function tuma_sync_products_from_pos() {
    $token = tuma_get_api_token();
    
    if (!$token) {
        return array(
            'success' => false,
            'message' => 'Failed to authenticate with Tuma API. Check your credentials.'
        );
    }
    
    $page = 1;
    $has_next = true;
    $synced = 0;
    $created = 0;
    $updated = 0;
    $errors = array();
    
    while ($has_next) {
        $response = wp_remote_get(
            add_query_arg(array('page' => $page, 'limit' => 100), 'https://api.tuma.co.ke/products'),
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                ),
                'timeout' => 45,
            )
        );
        
        if (is_wp_error($response)) {
            $errors[] = 'API request failed: ' . $response->get_error_message();
            break;
        }
        
        $results = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($results['data']['products'])) {
            break;
        }
        
        foreach ($results['data']['products'] as $item) {
            try {
                // Skip inactive products
                if (isset($item['is_active']) && !$item['is_active']) {
                    continue;
                }
                
                // Find existing product by SKU
                $product_id = wc_get_product_id_by_sku($item['sku']);
                $is_new = false;
                
                if ($product_id) {
                    $product = wc_get_product($product_id);
                    $updated++;
                } else {
                    $product = new WC_Product_Simple();
                    $product->set_sku($item['sku']);
                    $is_new = true;
                    $created++;
                }
                
                // Update product data
                $product->set_name($item['name']);
                $product->set_regular_price($item['price']);
                
                if (!empty($item['description'])) {
                    $product->set_description($item['description']);
                }
                
                $product->set_manage_stock(true);
                $product->set_stock_quantity($item['stock']);
                $product->set_stock_status($item['stock'] > 0 ? 'instock' : 'outofstock');
                $product->set_status('publish');
                
                // Store Tuma product ID for POS sync
                $product->update_meta_data('_tuma_product_id', $item['id']);
                $product->update_meta_data('_tuma_last_sync', current_time('mysql'));
                
                $saved_id = $product->save();
                
                // Download and set product image if available
                if (!empty($item['image_url']) && $saved_id) {
                    $image_id = tuma_download_product_image($item['image_url'], $item['name']);
                    if ($image_id) {
                        set_post_thumbnail($saved_id, $image_id);
                    }
                }
                
                $synced++;
                
            } catch (Exception $e) {
                $errors[] = 'Error syncing product ' . $item['sku'] . ': ' . $e->getMessage();
                error_log('Tuma product sync error: ' . $e->getMessage());
            }
        }
        
        $has_next = isset($results['data']['pagination']['has_next']) ? $results['data']['pagination']['has_next'] : false;
        $page++;
        
        // Prevent timeout on large catalogs
        if ($page > 20) {
            break;
        }
    }
    
    // Log sync completion
    error_log(sprintf('Tuma product sync completed: %d synced, %d created, %d updated', $synced, $created, $updated));
    
    return array(
        'success' => true,
        'synced'  => $synced,
        'created' => $created,
        'updated' => $updated,
        'errors'  => $errors,
    );
}

/**
 * Download and save product image
 */
function tuma_download_product_image($url, $name) {
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    
    // Fix relative URLs
    if (!preg_match('/^https?:\/\//', $url)) {
        $url = 'https://api.tuma.co.ke' . $url;
    }
    
    // Check for existing image
    global $wpdb;
    $existing_id = $wpdb->get_var($wpdb->prepare("
        SELECT post_id FROM $wpdb->postmeta
        WHERE meta_key = '_tuma_image_url'
        AND meta_value = %s
        LIMIT 1
    ", $url));
    
    if ($existing_id) {
        return intval($existing_id);
    }
    
    // Fetch image
    $response = wp_remote_get($url, array(
        'timeout' => 30,
        'headers' => array('User-Agent' => 'Mozilla/5.0'),
    ));
    
    if (is_wp_error($response)) {
        error_log('Tuma image download failed: ' . $response->get_error_message());
        return false;
    }
    
    $body = wp_remote_retrieve_body($response);
    
    // Detect invalid response (HTML instead of image)
    if (strpos($body, '<html') !== false || empty($body)) {
        return false;
    }
    
    $tmp = wp_tempnam($url);
    file_put_contents($tmp, $body);
    
    // Handle filename
    $decoded_url = urldecode($url);
    $path = parse_url($decoded_url, PHP_URL_PATH);
    $filename = basename($path);
    
    if (!$filename || strpos($filename, '.') === false) {
        $filename = sanitize_title($name) . '.jpg';
    }
    
    $file_array = array(
        'name'     => sanitize_file_name($filename),
        'tmp_name' => $tmp
    );
    
    $id = media_handle_sideload($file_array, 0);
    
    if (is_wp_error($id)) {
        error_log('Tuma media upload failed: ' . $id->get_error_message());
        @unlink($tmp);
        return false;
    }
    
    update_post_meta($id, '_tuma_image_url', $url);
    
    return $id;
}

// Add POS sync status column to products list
add_filter('manage_edit-product_columns', 'tuma_add_product_sync_column');
function tuma_add_product_sync_column($columns) {
    $columns['tuma_sync'] = 'POS Sync';
    return $columns;
}

add_action('manage_product_posts_custom_column', 'tuma_product_sync_column_content', 10, 2);
function tuma_product_sync_column_content($column, $post_id) {
    if ($column === 'tuma_sync') {
        $product = wc_get_product($post_id);
        if ($product) {
            $tuma_id = $product->get_meta('_tuma_product_id');
            $last_sync = $product->get_meta('_tuma_last_sync');
            
            if ($tuma_id) {
                echo '<span style="color: green;">✓ Synced</span>';
                if ($last_sync) {
                    echo '<br><small>' . esc_html($last_sync) . '</small>';
                }
            } else {
                echo '<span style="color: #999;">Not synced</span>';
            }
        }
    }
}
