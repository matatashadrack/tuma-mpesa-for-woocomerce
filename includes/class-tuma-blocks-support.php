<?php
/**
 * Tuma Payments WooCommerce Blocks Support
 *
 * @package Tuma_Payments
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Tuma Payments Blocks integration
 *
 * @since 1.2.0
 */
final class Tuma_Blocks_Support extends AbstractPaymentMethodType {

    /**
     * The gateway instance.
     *
     * @var WC_Tuma_Payments_Gateway
     */
    private $gateway;

    /**
     * Payment method name/id/slug.
     *
     * @var string
     */
    protected $name = 'tuma_payments';

    /**
     * Initializes the payment method type.
     */
    public function initialize() {
        $this->settings = get_option('woocommerce_tuma_payments_settings', array());
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = isset($gateways[$this->name]) ? $gateways[$this->name] : null;
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active() {
        if (!$this->gateway) {
            return false;
        }
        return $this->gateway->is_available();
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        $script_path = '/assets/js/blocks/tuma-blocks.js';
        $script_asset_path = plugin_dir_path(TUMA_WC_PLUGIN_FILE) . 'assets/js/blocks/tuma-blocks.asset.php';
        $script_asset = file_exists($script_asset_path)
            ? require($script_asset_path)
            : array(
                'dependencies' => array(),
                'version' => TUMA_WC_VER
            );
        $script_url = plugins_url($script_path, TUMA_WC_PLUGIN_FILE);

        wp_register_script(
            'tuma-payments-blocks',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('tuma-payments-blocks', 'tuma-payments', plugin_dir_path(TUMA_WC_PLUGIN_FILE) . 'languages/');
        }

        return array('tuma-payments-blocks');
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        return array(
            'title' => $this->get_setting('title', 'M-Pesa via Tuma Payments'),
            'description' => $this->get_setting('description', 'Pay securely using M-Pesa through Tuma Payments.'),
            'supports' => array_filter($this->gateway ? $this->gateway->supports : array(), array($this->gateway, 'supports')),
            'icon' => 'https://tuma.co.ke/assets/images/linkpay.png',
        );
    }
}
