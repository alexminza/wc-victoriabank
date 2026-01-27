<?php

/**
 * Plugin Name: Payment Gateway for Victoriabank for WooCommerce
 * Description: Accept Visa and Mastercard directly on your store with the Payment Gateway for Victoriabank for WooCommerce.
 * Plugin URI: https://github.com/alexminza/wc-victoriabank
 * Version: 1.6.0
 * Author: Alexander Minza
 * Author URI: https://profiles.wordpress.org/alexminza
 * Developer: Alexander Minza
 * Developer URI: https://profiles.wordpress.org/alexminza
 * Text Domain: wc-victoriabank
 * Domain Path: /languages
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires PHP: 7.2.5
 * Requires at least: 4.8
 * Tested up to: 6.9
 * WC requires at least: 3.3
 * WC tested up to: 10.4.3
 * Requires Plugins: woocommerce
 *
 * @package wc-victoriabank
 */

// Looking to contribute code to this plugin? Go ahead and fork the repository over at GitHub https://github.com/alexminza/wc-victoriabank
// This plugin is based on PHP SDK for Victoriabank API https://github.com/alexminza/victoriabank-sdk-php (https://packagist.org/packages/alexminza/victoriabank-sdk)

declare(strict_types=1);

namespace AlexMinza\WC_Payment_Gateway;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once __DIR__ . '/vendor/autoload_packages.php';

const VICTORIABANK_MOD_PLUGIN_FILE = __FILE__;

add_action('plugins_loaded', __NAMESPACE__ . '\victoriabank_plugins_loaded_init');

function victoriabank_plugins_loaded_init()
{
    // https://developer.woocommerce.com/docs/features/payments/payment-gateway-plugin-base/
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-victoriabank.php';

    //region Init payment gateway
    add_filter('woocommerce_payment_gateways', array(WC_Gateway_Victoriabank::class, 'add_payment_gateway'));

    if (is_admin()) {
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(WC_Gateway_Victoriabank::class, 'plugin_action_links'));

        //Add WooCommerce order actions
        add_filter('woocommerce_order_actions', array(WC_Gateway_Victoriabank::class, 'order_actions'), 10, 2);
        add_action('woocommerce_order_action_' . WC_Gateway_Victoriabank::MOD_ACTION_COMPLETE_TRANSACTION, array(WC_Gateway_Victoriabank::class, 'action_complete_transaction'));
        add_action('woocommerce_order_action_' . WC_Gateway_Victoriabank::MOD_ACTION_CHECK_PAYMENT, array(WC_Gateway_Victoriabank::class, 'action_check_payment'));
    }

    //Add WooCommerce email templates actions
    add_filter('woocommerce_email_order_meta_fields', array(WC_Gateway_Victoriabank::class, 'email_order_meta_fields'), 10, 3);

    //endregion
}

//region Declare WooCommerce compatibility
add_action(
    'before_woocommerce_init',
    function () {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            // WooCommerce HPOS compatibility
            // https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/#declaring-extension-incompatibility
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);

            // WooCommerce Cart Checkout Blocks compatibility
            // https://github.com/woocommerce/woocommerce/pull/36426
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }
    }
);
//endregion

//region Register WooCommerce Blocks payment method type
add_action(
    'woocommerce_blocks_loaded',
    function () {
        if (class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
            require_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-victoriabank-wbc.php';

            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function (\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                    $payment_method_registry->register(new WC_Gateway_Victoriabank_WBC(WC_Gateway_Victoriabank::MOD_ID));
                }
            );
        }
    }
);
//endregion
