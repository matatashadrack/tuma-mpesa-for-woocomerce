<?php

/**
 * @package Tuma Payments for WooCommerce
 * @author Tuma Payments < support@tuma.co.ke >
 * @version 1.0.0
 *
 * Plugin Name: Tuma Payments for WooCommerce
 * Plugin URI: https://merchant.tuma.co.ke/
 * Description: This plugin extends WordPress and WooCommerce functionality to integrate your online shop with bank accounts to accept and process online payments via M-Pesa.
 * Author: Shadrack Matata < support@tuma.co.ke >
 * Version: 1.0.0
 * Author URI: https://twitter.com/shadrac_matata/
 *
 * Requires at least: 4.6
 * Tested up to: 6.3
 *
 * WC requires at least: 3.5.0
 * WC tested up to: 8.0
 *
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

define('TUMA_WC_VER', '1.0.0');
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

            // Initialize gateway settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            
            // Add webhook handlers
            add_action('woocommerce_api_tuma_callback', array($this, 'webhook'));
            add_action('woocommerce_api_tuma_receipt', array($this, 'get_transaction_receipt'));
            add_action('woocommerce_api_tuma_resend', array($this, 'resend_payment_request'));
            add_action('woocommerce_api_tuma_status', array($this, 'get_payment_status'));
            
            // Hook thankyou page to show payment status
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
            add_action('woocommerce_admin_field_tuma_test_connection', array($this, 'generate_tuma_test_connection_html'));
            
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
                    <input id="tuma_phone" name="tuma_phone" type="tel" placeholder="0712345678" autocomplete="tel">
                    <small>Enter your M-Pesa registered phone number</small>
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
            
            // Basic phone validation
            if (!preg_match('/^(0|254)[0-9]{9}$/', $phone)) {
                wc_add_notice('Please enter a valid M-Pesa phone number (e.g., 0712345678)', 'error');
                return false;
            }

            return true;
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $phone = sanitize_text_field($_POST['tuma_phone']);

            // Get access token from Tuma API
            $token = $this->get_access_token();
            if (!$token) {
                wc_add_notice('Payment processing failed. Please try again.', 'error');
                return array('result' => 'fail');
            }

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
                // Store payment details in order meta
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
                echo '<h3 style="margin-top: 0; color: #28a745;"> Payment Instructions</h3>';
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
                        $reason = isset($data['message']) ? $data['message'] : 'Payment failed';
                        $order->update_status('failed', 'M-Pesa payment failed: ' . $reason);
                        break;
                        
                    case 'cancelled':
                        $order->update_status('cancelled', 'M-Pesa payment was cancelled by customer');
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
            
            // Get access token
            $token = $this->get_access_token();
            if (!$token) {
                wp_send_json_error('Authentication failed');
            }
            
            // Make STK push request
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

    // Add the gateway to WooCommerce
    function add_tuma_payments_gateway($gateways) {
        $gateways[] = 'WC_Tuma_Payments_Gateway';
        return $gateways;
    }
    add_filter('woocommerce_payment_gateways', 'add_tuma_payments_gateway');
}

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
