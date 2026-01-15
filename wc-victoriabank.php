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
 */

// Looking to contribute code to this plugin? Go ahead and fork the repository over at GitHub https://github.com/alexminza/wc-victoriabank
// This plugin is based on PHP SDK for Victoriabank API https://github.com/alexminza/victoriabank-sdk-php (https://packagist.org/packages/alexminza/victoriabank-sdk)

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once __DIR__ . '/vendor/autoload.php';

use Victoriabank\Victoriabank\VictoriabankClient;

add_action('plugins_loaded', 'victoriabank_plugins_loaded_init');

function victoriabank_plugins_loaded_init()
{
    // https://developer.woocommerce.com/docs/features/payments/payment-gateway-plugin-base/
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_Victoriabank extends WC_Payment_Gateway
    {
        //region Constants
        const MOD_ID          = 'victoriabank';
        const MOD_PREFIX      = 'vb_';
        const MOD_TITLE       = 'Victoriabank';
        const MOD_VERSION     = '1.6.0';

        const TRANSACTION_TYPE_CHARGE = 'charge';
        const TRANSACTION_TYPE_AUTHORIZATION = 'authorization';

        const LOGO_TYPE_BANK       = 'bank';
        const LOGO_TYPE_SYSTEMS    = 'systems';
        const LOGO_TYPE_NONE       = 'none';

        const MOD_TRANSACTION_TYPE = self::MOD_PREFIX . 'transaction_type';

        const SUPPORTED_CURRENCIES = array('MDL', 'EUR', 'USD');
        const ORDER_TEMPLATE       = 'Order #%1$s';

        const VB_ORDER    = 'ORDER';
        const VB_ORDER_ID = 'order_id';

        const VB_RRN      = self::MOD_PREFIX . 'RRN';
        const VB_INT_REF  = self::MOD_PREFIX . 'INT_REF';
        const VB_APPROVAL = self::MOD_PREFIX . 'APPROVAL';
        const VB_CARD     = self::MOD_PREFIX . 'CARD';

        const DEFAULT_TIMEOUT = 30; // seconds
        //endregion

        protected $logo_type, $testmode, $debug, $logger, $transaction_type, $order_template;
        protected $vb_base_url, $vb_merchant_id, $vb_merchant_terminal, $vb_merchant_name, $vb_merchant_url, $vb_merchant_address;
        protected $vb_bank_public_key_pem, $vb_private_key_pem, $vb_private_key_pass, $vb_private_key, $vb_bank_public_key;

        public function __construct()
        {
            $this->id                 = self::MOD_ID;
            $this->method_title       = self::MOD_TITLE;
            $this->method_description = __('Accept Visa and Mastercard through Victoriabank.', 'wc-victoriabank');
            $this->has_fields         = false;
            $this->supports           = array('products', 'refunds');

            //region Initialize settings
            $this->init_form_fields();
            $this->init_settings();

            $this->enabled     = $this->get_option('enabled', 'no');
            $this->title       = $this->get_option('title', $this->get_method_title());
            $this->description = $this->get_option('description');

            $this->logo_type   = $this->get_option('logo_type', self::LOGO_TYPE_BANK);
            $this->icon        = self::get_logo_icon($this->logo_type);

            $this->testmode    = wc_string_to_bool($this->get_option('testmode', 'no'));
            $this->debug       = wc_string_to_bool($this->get_option('debug', 'no'));
            $this->logger      = new WC_Logger(null, $this->debug ? WC_Log_Levels::DEBUG : WC_Log_Levels::INFO);

            if ($this->testmode) {
                $this->description = $this->get_test_message($this->description);
            }

            $this->transaction_type       = $this->get_option('transaction_type', self::TRANSACTION_TYPE_CHARGE);
            $this->order_template         = $this->get_option('order_template', self::ORDER_TEMPLATE);

            $this->vb_base_url            = $this->testmode ? VictoriabankClient::TEST_BASE_URL : VictoriabankClient::DEFAULT_BASE_URL;
            $this->vb_merchant_id         = $this->get_option('vb_merchant_id');
            $this->vb_merchant_terminal   = $this->get_option('vb_merchant_terminal');
            $this->vb_merchant_name       = $this->get_option('vb_merchant_name');
            $this->vb_merchant_url        = $this->get_option('vb_merchant_url');
            $this->vb_merchant_address    = $this->get_option('vb_merchant_address');

            $this->vb_bank_public_key_pem = $this->get_option('vb_bank_public_key_pem');
            $this->vb_private_key_pem     = $this->get_option('vb_private_key_pem');
            $this->vb_private_key_pass    = $this->get_option('vb_private_key_pass');

            $this->vb_private_key         = $this->get_option('vb_private_key');
            $this->vb_bank_public_key     = $this->get_option('vb_bank_public_key');

            $this->initialize_keys();
            //endregion

            if (is_admin()) {
                add_action("woocommerce_update_options_payment_gateways_{$this->id}", array($this, 'process_admin_options'));
                add_action('wp_ajax_victoriabank_callback_data_process', array($this, 'callback_data_process'));
            }

            add_action("woocommerce_receipt_{$this->id}", array($this, 'receipt_page'));
            add_action("woocommerce_api_wc_{$this->id}", array($this, 'check_response'));
            add_action("woocommerce_api_wc_{$this->id}_redirect", array($this, 'check_redirect'));
        }

        public function init_form_fields()
        {
            $blog_info_name = get_bloginfo('name');
            $home_url = home_url();
            $store_address = self::get_store_address();

            $this->form_fields = array(
                'enabled'         => array(
                    'title'       => __('Enable/Disable', 'wc-victoriabank'),
                    'type'        => 'checkbox',
                    'label'       => __('Enable this gateway', 'wc-victoriabank'),
                    'default'     => 'yes',
                ),
                'title'           => array(
                    'title'       => __('Title', 'wc-victoriabank'),
                    'type'        => 'text',
                    'description' => __('Payment method title that the customer will see during checkout.', 'wc-victoriabank'),
                    'desc_tip'    => true,
                    'default'     => $this->get_method_title(),
                    'custom_attributes' => array(
                        'required' => 'required',
                    ),
                ),
                'description'     => array(
                    'title'       => __('Description', 'wc-victoriabank'),
                    'type'        => 'textarea',
                    'description' => __('Payment method description that the customer will see during checkout.', 'wc-victoriabank'),
                    'desc_tip'    => true,
                    'default'     => __('Online payment with Visa / Mastercard bank cards issued by any bank in Moldova or abroad, processed through Victoriabank\'s online payment system.', 'wc-victoriabank'),
                ),
                'logo_type' => array(
                    'title'       => __('Logo', 'wc-victoriabank'),
                    'type'        => 'select',
                    'description' => __('Payment method logo image that the customer will see during checkout.', 'wc-victoriabank'),
                    'desc_tip'    => true,
                    'default'     => self::LOGO_TYPE_BANK,
                    'class'       => 'wc-enhanced-select',
                    'options'     => array(
                        self::LOGO_TYPE_BANK    => __('Bank logo', 'wc-victoriabank'),
                        self::LOGO_TYPE_SYSTEMS => __('Payment systems logos', 'wc-victoriabank'),
                        // self::LOGO_TYPE_NONE    => __('No logo', 'wc-victoriabank'),
                    ),
                ),

                'testmode'        => array(
                    'title'       => __('Test mode', 'wc-victoriabank'),
                    'type'        => 'checkbox',
                    'label'       => __('Enabled', 'wc-victoriabank'),
                    'description' => __('Use Test or Live bank gateway to process the payments. Disable when ready to accept live payments.', 'wc-victoriabank'),
                    'desc_tip'    => true,
                    'default'     => 'no',
                ),
                'debug'           => array(
                    'title'       => __('Debug mode', 'wc-victoriabank'),
                    'type'        => 'checkbox',
                    'label'       => __('Enable logging', 'wc-victoriabank'),
                    'description' => sprintf('<a href="%2$s">%1$s</a>', esc_html__('View logs', 'wc-victoriabank'), esc_url(self::get_logs_url())),
                    'desc_tip'    => __('Save debug messages to the WooCommerce System Status logs. Note: this may log personal information. Use this for debugging purposes only and delete the logs when finished.', 'wc-victoriabank'),
                    'default'     => 'no',
                ),

                'transaction_type' => array(
                    'title'       => __('Transaction type', 'wc-victoriabank'),
                    'type'        => 'select',
                    'description' => __('Select how transactions should be processed. Charge submits all transactions for settlement, Authorization simply authorizes the order total for capture later.', 'wc-victoriabank'),
                    'desc_tip'    => true,
                    'default'     => self::TRANSACTION_TYPE_CHARGE,
                    'class'       => 'wc-enhanced-select',
                    'options'     => array(
                        self::TRANSACTION_TYPE_CHARGE        => __('Charge', 'wc-victoriabank'),
                        self::TRANSACTION_TYPE_AUTHORIZATION => __('Authorization', 'wc-victoriabank'),
                    ),
                ),
                'order_template'  => array(
                    'title'       => __('Order description', 'wc-victoriabank'),
                    'type'        => 'text',
                    /* translators: 1: Example placeholder shown to user, represents Order ID */
                    'description' => __('Format: <code>%1$s</code> - Order ID', 'wc-victoriabank'),
                    'desc_tip'    => __('Order description that the customer will see on the bank payment page.', 'wc-victoriabank'),
                    'default'     => self::ORDER_TEMPLATE,
                    'custom_attributes' => array(
                        'required'  => 'required',
                        'maxlength' => 50,
                    ),
                ),

                'merchant_settings' => array(
                    'title'       => __('Merchant Data', 'wc-victoriabank'),
                    'description' => __('Merchant information that the customer will see on the bank payment page.', 'wc-victoriabank'),
                    'type'        => 'title',
                ),
                'vb_merchant_name' => array(
                    'title'       => __('Merchant name', 'wc-victoriabank'),
                    'type'        => 'text',
                    'description' => esc_html($blog_info_name),
                    'desc_tip'    => __('Latin symbols', 'wc-victoriabank'),
                    'default'     => $blog_info_name,
                    'custom_attributes' => array(
                        'required'  => 'required',
                        'maxlength' => 50,
                    ),
                ),
                'vb_merchant_url' => array(
                    'title'       => __('Merchant URL', 'wc-victoriabank'),
                    'type'        => 'text',
                    'description' => esc_url($home_url),
                    'default'     => $home_url,
                    'custom_attributes' => array(
                        'required'  => 'required',
                        'maxlength' => 250,
                    ),
                ),
                'vb_merchant_address' => array(
                    'title'       => __('Merchant address', 'wc-victoriabank'),
                    'type'        => 'text',
                    'description' => esc_html($store_address),
                    'default'     => $store_address,
                    'custom_attributes' => array(
                        'required'  => 'required',
                        'maxlength' => 250,
                    ),
                ),
                'vb_merchant_id'  => array(
                    'title'       => __('Merchant ID', 'wc-victoriabank'),
                    'type'        => 'text',
                    'description' => __('Example: 498000049812345', 'wc-victoriabank'),
                    'custom_attributes' => array(
                        'required'  => 'required',
                        'minlength' => 15,
                        'maxlength' => 15,
                    ),
                ),
                'vb_merchant_terminal' => array(
                    'title'       => __('Terminal ID', 'wc-victoriabank'),
                    'type'        => 'text',
                    'description' => __('Example: 49812345', 'wc-victoriabank'),
                    'custom_attributes' => array(
                        'required'  => 'required',
                        'minlength' => 8,
                        'maxlength' => 8,
                    ),
                ),

                'connection_settings' => array(
                    'title'       => __('Connection Settings', 'wc-victoriabank'),
                    'type'        => 'title',
                    'description' => sprintf(
                        '%1$s<br /><br /><a href="#" id="woocommerce_victoriabank_basic_settings" class="button">%2$s</a> <a href="#" id="woocommerce_victoriabank_advanced_settings" class="button">%3$s</a>',
                        esc_html__('Use Basic settings to upload the key files received from the bank or configure manually using Advanced settings below.', 'wc-victoriabank'),
                        esc_html__('Basic settings&raquo;', 'wc-victoriabank'),
                        esc_html__('Advanced settings&raquo;', 'wc-victoriabank')
                    ),
                ),
                'vb_bank_public_key_pem' => array(
                    'title'       => __('Bank public key', 'wc-victoriabank'),
                    'type'        => 'file',
                    'description' => '<code>victoria_pub.pem</code>',
                    'custom_attributes' => array(
                        'accept' => '.pem',
                    ),
                ),
                'vb_private_key_pem' => array(
                    'title'       => __('Merchant private key', 'wc-victoriabank'),
                    'type'        => 'file',
                    'description' => '<code>key.pem</code>',
                    'custom_attributes' => array(
                        'accept' => '.pem',
                    ),
                ),

                'vb_bank_public_key' => array(
                    'title'       => __('Bank public key', 'wc-victoriabank'),
                    'type'        => 'textarea',
                    'description' => '<code>file:///path/to/victoria_pub.pem</code>',
                    'placeholder' => "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----",
                ),
                'vb_private_key'  => array(
                    'title'       => __('Merchant Private key', 'wc-victoriabank'),
                    'type'        => 'textarea',
                    'description' => '<code>file:///path/to/key.pem</code>',
                    'placeholder' => "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----",
                ),
                'vb_private_key_pass' => array(
                    'title'       => __('Private key passphrase', 'wc-victoriabank'),
                    'type'        => 'password',
                    'description' => __('Leave empty if private key is not encrypted.', 'wc-victoriabank'),
                    'desc_tip'    => true,
                    'placeholder' => __('Optional', 'wc-victoriabank'),
                ),

                'payment_notification' => array(
                    'title'       => __('Payment Notification', 'wc-victoriabank'),
                    'type'        => 'title',
                    'description' => sprintf(
                        '%1$s<br /><br /><b>%2$s:</b> <code>%3$s</code><br /><br /><a href="#" id="woocommerce_victoriabank_payment_notification_advanced" class="button">%4$s</a>',
                        esc_html__('Provide this URL to the bank to enable online payment notifications.', 'wc-victoriabank'),
                        esc_html__('Callback URL', 'wc-victoriabank'),
                        esc_url($this->get_callback_url()),
                        esc_html__('Advanced&raquo;', 'wc-victoriabank')
                    ),
                ),
                'vb_callback_data'  => array(
                    'title'       => __('Process callback data', 'wc-victoriabank'),
                    'type'        => 'textarea',
                    'description' => '<a href="#" id="woocommerce_victoriabank_callback_data_process" class="button">Process</a>',
                    'desc_tip'    => __('Manually process bank transaction response callback data received by email as part of the backup procedure.', 'wc-victoriabank'),
                    'placeholder' => "TERMINAL=49812345\nTRTYPE=0\nORDER=000123\nAMOUNT=123.45\nCURRENCY=MDL\n...",
                ),
            );
        }

        protected static function get_logo_icon(string $logo_type)
        {
            switch ($logo_type) {
                case self::LOGO_TYPE_BANK:
                    return plugins_url('/assets/img/victoriabank.png', __FILE__);
                case self::LOGO_TYPE_SYSTEMS:
                    return plugins_url('/assets/img/paymentsystems.png', __FILE__);
                case self::LOGO_TYPE_NONE:
                    return '';
            }

            return '';
        }

        public function is_valid_for_use()
        {
            if (!in_array(get_woocommerce_currency(), self::SUPPORTED_CURRENCIES, true)) {
                return false;
            }

            return true;
        }

        public function is_available()
        {
            if (!$this->is_valid_for_use()) {
                return false;
            }

            if (!$this->check_settings()) {
                return false;
            }

            return parent::is_available();
        }

        public function needs_setup()
        {
            return !$this->check_settings();
        }

        public function admin_options()
        {
            $this->validate_settings();
            $this->display_errors();

            // https://developer.woocommerce.com/2025/11/19/deprecation-of-wc_enqueue_js-in-10-4/
            $script_handle = self::MOD_PREFIX . 'connection_settings';
            wp_register_script($script_handle, '', array('jquery'), self::MOD_VERSION, true);
            wp_enqueue_script($script_handle);

            wp_add_inline_script(
                $script_handle,
                'jQuery(function() {
                    var vb_connection_basic_fields_ids      = "#woocommerce_victoriabank_vb_bank_public_key_pem, #woocommerce_victoriabank_vb_private_key_pem, #woocommerce_victoriabank_vb_private_key_pass";
                    var vb_connection_advanced_fields_ids   = "#woocommerce_victoriabank_vb_bank_public_key, #woocommerce_victoriabank_vb_private_key, #woocommerce_victoriabank_vb_private_key_pass";
                    var vb_notification_advanced_fields_ids = "#woocommerce_victoriabank_vb_callback_data";

                    var vb_connection_basic_fields      = jQuery(vb_connection_basic_fields_ids).closest("tr");
                    var vb_connection_advanced_fields   = jQuery(vb_connection_advanced_fields_ids).closest("tr");
                    var vb_notification_advanced_fields = jQuery(vb_notification_advanced_fields_ids).closest("tr");

                    jQuery(document).ready(function() {
                        vb_connection_basic_fields.hide();
                        vb_connection_advanced_fields.hide();
                        vb_notification_advanced_fields.hide();
                    });

                    jQuery("#woocommerce_victoriabank_basic_settings").on("click", function() {
                        vb_connection_advanced_fields.hide();
                        vb_connection_basic_fields.show();
                        return false;
                    });

                    jQuery("#woocommerce_victoriabank_advanced_settings").on("click", function() {
                        vb_connection_basic_fields.hide();
                        vb_connection_advanced_fields.show();
                        return false;
                    });

                    jQuery("#woocommerce_victoriabank_payment_notification_advanced").on("click", function() {
                        vb_notification_advanced_fields.show();
                        return false;
                    });

                    jQuery("#woocommerce_victoriabank_callback_data_process").on("click", function() {
                        if(!confirm("' . esc_js(esc_html__('Are you sure you want to process the entered bank transaction response callback data?', 'wc-victoriabank')) . '"))
                            return false;

                        var $this = jQuery(this);

                        if($this.attr("disabled"))
                            return false;

                        $this.attr("disabled", true);
                        var callback_data = jQuery("#woocommerce_victoriabank_vb_callback_data").val();

                        jQuery.ajax({
                            type: "POST",
                            data: {
                                _ajax_nonce: "' . wp_create_nonce('callback_data_process') . '",
                                action: "victoriabank_callback_data_process",
                                callback_data: callback_data
                            },
                            dataType: "json",
                            url: ajaxurl,
                            complete: function(response, textStatus) {
                                $this.attr("disabled", false);

                                if(response.responseJSON && response.responseJSON.data) {
                                    alert(response.responseJSON.data);
                                } else {
                                    alert(response.responseText);
                                }
                            }
                        });

                        return false;
                    });
                });'
            );

            parent::admin_options();
        }

        public function process_admin_options()
        {
            unset($_POST['woocommerce_victoriabank_vb_callback_data']);

            $this->process_pem_setting('woocommerce_victoriabank_vb_bank_public_key_pem', $this->vb_bank_public_key_pem, 'woocommerce_victoriabank_vb_bank_public_key', 'victoria_pub.pem');
            $this->process_pem_setting('woocommerce_victoriabank_vb_private_key_pem', $this->vb_private_key_pem, 'woocommerce_victoriabank_vb_private_key', 'key.pem');

            return parent::process_admin_options();
        }

        protected function check_settings()
        {
            return !empty($this->vb_bank_public_key)
                && !empty($this->vb_private_key);
        }

        protected function validate_settings()
        {
            $validate_result = true;

            if (!$this->is_valid_for_use()) {
                $this->add_error(
                    sprintf(
                        '<strong>%1$s: %2$s</strong>. %3$s: %4$s',
                        esc_html__('Unsupported store currency', 'wc-victoriabank'),
                        esc_html(get_woocommerce_currency()),
                        esc_html__('Supported currencies', 'wc-victoriabank'),
                        esc_html(join(', ', self::SUPPORTED_CURRENCIES))
                    )
                );

                $validate_result = false;
            }

            if (!$this->check_settings()) {
                /* translators: 1: Plugin installation instructions URL */
                $message_instructions = sprintf(__('See plugin documentation for <a href="%1$s" target="_blank">installation instructions</a>.', 'wc-victoriabank'), 'https://wordpress.org/plugins/wc-victoriabank/#installation');
                $this->add_error(sprintf('<strong>%1$s</strong>: %2$s. %3$s', esc_html__('Connection Settings', 'wc-victoriabank'), esc_html__('Not configured', 'wc-victoriabank'), wp_kses_post($message_instructions)));
                $validate_result = false;
            } else {
                $result = $this->validate_public_key($this->vb_bank_public_key);
                if (!empty($result)) {
                    $this->add_error(sprintf('<strong>%1$s</strong>: %2$s', esc_html__('Bank public key file', 'wc-victoriabank'), esc_html($result)));
                    $validate_result = false;
                }

                $result = $this->validate_private_key($this->vb_private_key, $this->vb_private_key_pass);
                if (!empty($result)) {
                    $this->add_error(sprintf('<strong>%1$s</strong>: %2$s', esc_html__('Private key file', 'wc-victoriabank'), esc_html($result)));
                    $validate_result = false;
                }
            }

            if (!ini_get('allow_url_fopen')) {
                $this->add_error(sprintf('<strong>PHP %1$s</strong>: %2$s', 'allow_url_fopen', wp_kses_post(__('Current server settings do not allow web requests to the bank payment gateway. See <a href="https://www.php.net/manual/en/filesystem.configuration.php#ini.allow-url-fopen" target="_blank">PHP Runtime Configuration</a> for details.', 'wc-victoriabank'))));
                $validate_result = false;
            }

            return $validate_result;
        }

        protected function settings_admin_notice()
        {
            if (self::is_wc_admin()) {
                /* translators: 1: Plugin settings URL */
                $message = sprintf(wp_kses_post(__('Please review the <a href="%1$s">payment method settings</a> page for log details and setup instructions.', 'wc-victoriabank')), esc_url(self::get_settings_url()));
                wc_add_notice($message, 'error');
            }
        }

        //region Keys
        protected function process_pem_setting(string $pem_field_id, string $pem_option_value, string $pem_target_field_id, string $pem_type)
        {
            try {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by WooCommerce.
                if (array_key_exists($pem_field_id, $_FILES)) {
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- File validation is performed via is_uploaded_file and error check. Nonce verification is handled by WooCommerce.
                    $pem_file = $_FILES[$pem_field_id];
                    $tmp_name = $pem_file['tmp_name'];

                    if (UPLOAD_ERR_OK === $pem_file['error'] && is_uploaded_file($tmp_name)) {
                        $wp_filesystem = self::get_wp_filesystem();
                        $pem_data = $wp_filesystem->get_contents($tmp_name);

                        if (false !== $pem_data) {
                            $result = $this->save_temp_file($pem_data, $pem_type);

                            if (!empty($result)) {
                                // Overwrite advanced setting value
                                $_POST[$pem_target_field_id] = $result;
                                // Save uploaded file to settings
                                $_POST[$pem_field_id] = $pem_data;

                                return;
                            }
                        }
                    }
                }
            } catch (Exception $ex) {
                $this->log(
                    $ex->getMessage(),
                    WC_Log_Levels::ERROR,
                    array(
                        'pem_field_id' => $pem_field_id,
                        'pem_target_field_id' => $pem_target_field_id,
                        'pem_type' => $pem_type,
                        'exception' => (string) $ex,
                        'backtrace' => true,
                    )
                );
            }

            // Preserve existing value
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by WooCommerce.
            $_POST[$pem_field_id] = $pem_option_value;
        }

        protected function initialize_keys()
        {
            $this->initialize_key($this->vb_bank_public_key, $this->vb_bank_public_key_pem, 'vb_bank_public_key', 'victoria_pub.pem');
            $this->initialize_key($this->vb_private_key, $this->vb_private_key_pem, 'vb_private_key', 'key.pem');
        }

        protected function initialize_key(string &$pem_file, string $pem_data, string $pem_option_name, string $pem_type)
        {
            try {
                if (!is_readable($pem_file)) {
                    if (self::is_overwritable($pem_file)) {
                        if (!empty($pem_data)) {
                            $result = $this->save_temp_file($pem_data, $pem_type);

                            if (!empty($result)) {
                                $this->update_option($pem_option_name, $result);
                                $pem_file = $result;
                            }
                        }
                    }
                }
            } catch (Exception $ex) {
                $this->log(
                    $ex->getMessage(),
                    WC_Log_Levels::ERROR,
                    array(
                        'pem_file' => $pem_file,
                        'pem_option_name' => $pem_option_name,
                        'pem_type' => $pem_type,
                        'exception' => (string) $ex,
                        'backtrace' => true,
                    )
                );
            }
        }

        protected function validate_public_key(string $key_file)
        {
            try {
                $wp_filesystem = self::get_wp_filesystem();
                $key_data = $wp_filesystem->get_contents($key_file);
                $public_key = openssl_pkey_get_public($key_data);

                if (false === $public_key) {
                    $message = __('Invalid public key', 'wc-victoriabank');
                    $this->log_openssl_errors($message);
                    return $message;
                }
            } catch (Exception $ex) {
                $this->log(
                    $ex->getMessage(),
                    WC_Log_Levels::ERROR,
                    array(
                        'key_file' => $key_file,
                        'exception' => (string) $ex,
                        'backtrace' => true,
                    )
                );

                return __('Could not validate public key', 'wc-victoriabank');
            }
        }

        protected function validate_private_key(string $key_file, string $key_passphrase)
        {
            try {
                $wp_filesystem = self::get_wp_filesystem();
                $key_data = $wp_filesystem->get_contents($key_file);
                $private_key = openssl_pkey_get_private($key_data, $key_passphrase);

                if (false === $private_key) {
                    $message = __('Invalid private key or wrong private key passphrase', 'wc-victoriabank');
                    $this->log_openssl_errors($message);
                    return $message;
                }
            } catch (Exception $ex) {
                $this->log(
                    $ex->getMessage(),
                    WC_Log_Levels::ERROR,
                    array(
                        'key_file' => $key_file,
                        'exception' => (string) $ex,
                        'backtrace' => true,
                    )
                );

                return __('Could not validate private key', 'wc-victoriabank');
            }
        }

        protected function log_openssl_errors(string $message)
        {
            $openssl_errors = array();

            // https://www.php.net/manual/en/function.openssl-error-string.php
            // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Common openssl_error_string code pattern.
            while ($error = openssl_error_string()) {
                $openssl_errors[] = $error;
            }

            $this->log(
                $message,
                WC_Log_Levels::ERROR,
                array(
                    'openssl_errors' => $openssl_errors,
                    'backtrace' => true,
                )
            );
        }

        /**
         * @global WP_Filesystem_Base $wp_filesystem
         */
        protected static function get_wp_filesystem()
        {
            /**
             * @var WP_Filesystem_Base
             */
            global $wp_filesystem;

            if (empty($wp_filesystem)) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }

            return $wp_filesystem;
        }

        protected function save_temp_file(string $file_data, string $file_suffix = '')
        {
            $wp_filesystem = self::get_wp_filesystem();

            $temp_file_name = sprintf('%1$s%2$s_', self::MOD_PREFIX, $file_suffix);
            $temp_file = wp_tempnam($temp_file_name);

            if (!$wp_filesystem->put_contents($temp_file, $file_data, FS_CHMOD_FILE)) {
                /* translators: 1: Temporary file name */
                $this->log(sprintf(__('Unable to save data to temporary file: %1$s', 'wc-victoriabank'), $temp_file), WC_Log_Levels::ERROR);
                return null;
            }

            return $temp_file;
        }

        protected static function is_temp_file(string $file_name)
        {
            $temp_dir = get_temp_dir();
            return strncmp($file_name, $temp_dir, strlen($temp_dir)) === 0;
        }

        protected static function is_overwritable(string $file_name)
        {
            return empty($file_name) || self::is_temp_file($file_name);
        }
        //endregion

        //region Payment
        protected function init_victoriabank_client()
        {
            $options = array(
                'base_uri' => $this->vb_base_url,
                'timeout'  => self::DEFAULT_TIMEOUT,
            );

            if ($this->debug) {
                $log_name = "{$this->id}_guzzle";
                $log_file_name = WC_Log_Handler_File::get_log_file_path($log_name);

                $log = new \Monolog\Logger($log_name);
                $log->pushHandler(new \Monolog\Handler\StreamHandler($log_file_name, \Monolog\Logger::DEBUG));

                $stack = \GuzzleHttp\HandlerStack::create();
                $stack->push(\GuzzleHttp\Middleware::log($log, new \GuzzleHttp\MessageFormatter(\GuzzleHttp\MessageFormatter::DEBUG)));

                $options['handler'] = $stack;
            }

            $guzzle_client = new \GuzzleHttp\Client($options);
            $client = new VictoriabankClient($guzzle_client);

            $client
                ->setMerchantId($this->vb_merchant_id)
                ->setTerminalId($this->vb_merchant_terminal)
                ->setMerchantUrl($this->vb_merchant_url)
                ->setMerchantName($this->vb_merchant_name)
                ->setMerchantAddress($this->vb_merchant_address)
                ->setTimezone(wc_timezone_string())
                ->setCountry(WC()->countries->get_base_country())
                ->setMerchantPrivateKey($this->vb_private_key, $this->vb_private_key_pass)
                ->setBankPublicKey($this->vb_bank_public_key)
                ->setSignatureAlgo(VictoriabankClient::P_SIGN_HASH_ALGO_SHA256);

            return $client;
        }

        /**
         * @param int $order_id
         */
        public function process_payment($order_id)
        {
            $is_store_api_request = WC()->is_store_api_request();

            if (!$this->check_settings()) {
                /* translators: 1: Payment method title */
                $message = esc_html(sprintf(__('%1$s is not properly configured.', 'wc-victoriabank'), $this->get_method_title()));

                // https://github.com/woocommerce/woocommerce/issues/48687#issuecomment-2186475264
                if ($is_store_api_request) {
                    throw new Exception(esc_html($message));
                }

                wc_add_notice($message, 'error');
                $this->settings_admin_notice();

                return array(
                    'result'   => 'failure',
                    'messages' => $message,
                );
            }

            // https://github.com/woocommerce/woocommerce/issues/48126#issuecomment-2180991020
            if ($is_store_api_request || is_ajax()) {
                $order = wc_get_order($order_id);

                return array(
                    'result'   => 'success',
                    'redirect' => $order->get_checkout_payment_url(true),
                );
            }

            $this->receipt_page($order_id);
        }

        public function complete_transaction(\WC_Order $order)
        {
            $order_id = $order->get_id();

            $this->log(
                __FUNCTION__,
                WC_Log_Levels::DEBUG,
                array(
                    'order_id' => $order_id,
                    'backtrace' => true,
                )
            );

            $order_total = self::get_order_net_total($order);
            $order_currency = $order->get_currency();

            $rrn = strval($order->get_meta(strtolower(self::VB_RRN), true));
            $int_ref = strval($order->get_meta(strtolower(self::VB_INT_REF), true));
            if (empty($rrn)) {
                /* translators: 1: Order ID, 2: Meta field key */
                $message = esc_html(sprintf(__('Order #%1$s missing meta field %2$s.', 'wc-victoriabank'), $order_id, self::VB_RRN));
                return new WP_Error('order_rrn', $message);
            }
            if (empty($int_ref)) {
                /* translators: 1: Order ID, 2: Meta field key */
                $message = esc_html(sprintf(__('Order #%1$s missing meta field %2$s.', 'wc-victoriabank'), $order_id, self::VB_INT_REF));
                return new WP_Error('order_int_ref', $message);
            }

            // Funds locked on bank side - transfer the product/service to the customer and request completion
            $completion_result = null;
            try {
                $client = $this->init_victoriabank_client();
                $completion_result = $client->orderComplete(strval($order_id), $order_total, $order_currency, $rrn, $int_ref);
            } catch (Exception $ex) {
                $this->log(
                    $ex->getMessage(),
                    WC_Log_Levels::ERROR,
                    array(
                        'order_id' => $order_id,
                        'order_total' => $order_total,
                        'completion_result' => wp_json_encode($completion_result),
                        'exception' => (string) $ex,
                        'backtrace' => true,
                    )
                );
            }

            if (empty($completion_result)) {
                /* translators: 1: Order ID, 2: Payment method title */
                $message = esc_html(sprintf(__('Order #%1$s payment completion via %2$s failed.', 'wc-victoriabank'), $order_id, $this->get_method_title()));
                $message = $this->get_test_message($message);
                $this->log(
                    $message,
                    WC_Log_Levels::ERROR,
                    array(
                        'order_id' => $order_id,
                        'order_total' => $order_total,
                        'completion_result' => wp_json_encode($completion_result),
                    )
                );

                $order->add_order_note($message);
                return new WP_Error('complete_transaction', $message);
            }

            return true;
        }

        protected function check_transaction(\WC_Order $order, array $bank_response)
        {
            $payment_data_order_id = intval(VictoriabankClient::deNormalizeOrderId($bank_response['ORDER']));
            $payment_data_amount   = floatval($bank_response['AMOUNT']);
            $payment_data_currency = strval($bank_response['CURRENCY']);

            $order_id = $order->get_id();
            $order_total = $order->get_total();
            $order_currency = $order->get_currency();

            $order_price = $this->format_price($order_total, $order_currency);
            $payment_data_price = $this->format_price($payment_data_amount, $payment_data_currency);

            if ($order_id !== $payment_data_order_id || $order_price !== $payment_data_price) {
                /* translators: 1: Payment data order ID, 2: Payment data price, 3: Order ID, 4: Order total price */
                $message = sprintf(__('Order payment data mismatch: Payment: #%1$s %2$s, Order: #%3$s %4$s.', 'wc-victoriabank'), $payment_data_order_id, $payment_data_price, $order_id, $order_price);
                $this->log($message, WC_Log_Levels::ERROR);

                return false;
            }

            $tr_type = strval($bank_response['TRTYPE']);
            if (VictoriabankClient::TRTYPE_REVERSAL === $tr_type) {
                return $payment_data_amount <= $order_total;
            }

            return true;
        }

        public function check_redirect()
        {
            $this->log_request(__FUNCTION__);

            // Received payment data from VB here instead of CallbackURL?
            $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
            if ('POST' === $request_method) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verification is done via bank signature in process_response_data.
                $this->process_response_data($_POST);
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verification is done via order existence check.
            $order_id = isset($_REQUEST[self::VB_ORDER_ID]) ? absint(wp_unslash($_REQUEST[self::VB_ORDER_ID])) : 0;
            if (empty($order_id)) {
                /* translators: 1: Payment method title */
                $message = esc_html(sprintf(__('Payment verification failed: Order ID not received from %1$s.', 'wc-victoriabank'), $this->get_method_title()));
                $this->log($message, WC_Log_Levels::ERROR);

                wc_add_notice($message, 'error');
                $this->settings_admin_notice();

                wp_safe_redirect(wc_get_cart_url());
                return false;
            }

            $order = wc_get_order($order_id);
            if (empty($order)) {
                /* translators: 1: Order ID, 2: Payment method title */
                $message = esc_html(sprintf(__('Order #%1$s not found as received from %2$s.', 'wc-victoriabank'), $order_id, $this->get_method_title()));
                $this->log($message, WC_Log_Levels::ERROR);

                wc_add_notice($message, 'error');
                $this->settings_admin_notice();

                wp_safe_redirect(wc_get_cart_url());
                return false;
            }

            if ($order->is_paid()) {
                WC()->cart->empty_cart();

                /* translators: 1: Order ID, 2: Payment method title */
                $message = esc_html(sprintf(__('Order #%1$s paid successfully via %2$s.', 'wc-victoriabank'), $order_id, $this->get_method_title()));
                $this->log($message, WC_Log_Levels::INFO);

                wc_add_notice($message, 'success');

                wp_safe_redirect($this->get_return_url($order));
                return true;
            } else {
                /* translators: 1: Order ID, 2: Payment method title */
                $message = esc_html(sprintf(__('Order #%1$s payment failed via %2$s.', 'wc-victoriabank'), $order_id, $this->get_method_title()));
                $this->log($message, WC_Log_Levels::ERROR);

                wc_add_notice($message, 'error');
                $this->settings_admin_notice();

                wp_safe_redirect($order->get_checkout_payment_url()); // wc_get_checkout_url()
                return false;
            }
        }

        public function check_response()
        {
            $this->log_request(__FUNCTION__);

            $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
            if ('GET' === $request_method) {
                $message = __('This Callback URL works and should not be called directly.', 'wc-victoriabank');

                wc_add_notice($message, 'notice');

                wp_safe_redirect(wc_get_cart_url());
                return false;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verification is done via bank signature in process_response_data
            return $this->process_response_data($_POST);
        }

        public function process_response_data(array $bank_response)
        {
            $this->log(
                __FUNCTION__,
                WC_Log_Levels::DEBUG,
                array(
                    'bank_response' => $bank_response,
                    'backtrace' => true,
                )
            );

            $check_result = null;
            try {
                $client = $this->init_victoriabank_client();
                $check_result = $client->validateResponse($bank_response);
            } catch (Exception $ex) {
                $this->log(
                    $ex->getMessage(),
                    WC_Log_Levels::ERROR,
                    array(
                        'bank_response' => $bank_response,
                        'check_result' => $check_result,
                        'exception' => (string) $ex,
                        'backtrace' => true,
                    )
                );
            }

            //region Extract bank response params
            $tr_type   = strval($bank_response['TRTYPE']);
            $order_id  = VictoriabankClient::deNormalizeOrderId($bank_response['ORDER']);
            $amount    = floatval($bank_response['AMOUNT']);
            $currency  = strval($bank_response['CURRENCY']);
            $approval  = strval($bank_response['APPROVAL']);
            $rrn       = strval($bank_response['RRN']);
            $int_ref   = strval($bank_response['INT_REF']);
            $timestamp = strval($bank_response['TIMESTAMP']);
            $text      = strval($bank_response['TEXT']);
            $bin       = strval($bank_response['BIN']);
            $card      = strval($bank_response['CARD']);

            $bank_params = array(
                'ORDER'     => $order_id,
                'AMOUNT'    => $amount,
                'CURRENCY'  => $currency,
                'TEXT'      => $text,
                'APPROVAL'  => $approval,
                'RRN'       => $rrn,
                'INT_REF'   => $int_ref,
                'TIMESTAMP' => $timestamp,
                'BIN'       => $bin,
                'CARD'      => $card,
            );
            //endregion

            //region Validate order
            if (empty($order_id)) {
                /* translators: 1: Payment method title */
                $message = esc_html(sprintf(__('Order ID not received from %1$s.', 'wc-victoriabank'), $this->get_method_title()));
                $this->log($message, WC_Log_Levels::ERROR);
                return false;
            }

            $order = wc_get_order($order_id);
            if (empty($order)) {
                /* translators: 1: Order ID, 2: Payment method title */
                $message = esc_html(sprintf(__('Order #%1$s not found as received from %2$s.', 'wc-victoriabank'), $order_id, $this->get_method_title()));
                $this->log($message, WC_Log_Levels::ERROR);
                return false;
            }
            //endregion

            $check_transaction = $this->check_transaction($order, $bank_response);
            if ($check_result && $check_transaction) {
                switch ($tr_type) {
                    case VictoriabankClient::TRTYPE_AUTHORIZATION:
                        if ($order->is_paid()) {
                            /* translators: 1: Order ID */
                            $message = sprintf(__('Order #%1$s already fully paid.', 'wc-victoriabank'), $order_id);
                            $this->log($message, WC_Log_Levels::WARNING);

                            return true;
                        }

                        //region Update order payment metadata
                        // https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book
                        $order->add_meta_data(self::MOD_TRANSACTION_TYPE, $this->transaction_type, true);

                        foreach ($bank_params as $key => $value) {
                            $order->add_meta_data(strtolower(self::MOD_PREFIX . $key), $value, true);
                        }

                        $order->save();
                        //endregion

                        /* translators: 1: Order ID, 2: Payment method title, 3: Payment data */
                        $message = esc_html(sprintf(__('Order #%1$s payment authorized via %2$s: %3$s', 'wc-victoriabank'), $order_id, $this->get_method_title(), $rrn));
                        $message = $this->get_test_message($message);
                        $this->log(
                            $message,
                            WC_Log_Levels::INFO,
                            array(
                                'bank_response' => $bank_response,
                                'bank_params' => $bank_params,
                                'check_result' => $check_result,
                                'check_transaction' => $check_transaction,
                            )
                        );

                        $order->add_order_note($message);
                        $order->payment_complete($rrn);

                        switch ($this->transaction_type) {
                            case self::TRANSACTION_TYPE_CHARGE:
                                $this->complete_transaction($order);
                                break;

                            case self::TRANSACTION_TYPE_AUTHORIZATION:
                                break;

                            default:
                                $this->log(sprintf('Unknown order #%1$s transaction type: %2$s', $order_id, $this->transaction_type), WC_Log_Levels::ERROR);
                                break;
                        }

                        return true;

                    case VictoriabankClient::TRTYPE_SALES_COMPLETION:
                        /* translators: 1: Order ID, 2: Payment method title, 3: Payment data */
                        $message = esc_html(sprintf(__('Order #%1$s payment completed via %2$s: %3$s', 'wc-victoriabank'), $order_id, $this->get_method_title(), $rrn));
                        $message = $this->get_test_message($message);
                        $this->log(
                            $message,
                            WC_Log_Levels::INFO,
                            array(
                                'bank_response' => $bank_response,
                                'bank_params' => $bank_params,
                                'check_result' => $check_result,
                                'check_transaction' => $check_transaction,
                            )
                        );

                        $order->add_order_note($message);
                        return true;

                    case VictoriabankClient::TRTYPE_REVERSAL:
                        /* translators: 1: Order ID, 2: Refund amount, 3: Payment method title */
                        $message = esc_html(sprintf(__('Order #%1$s refund of %2$s via %3$s approved.', 'wc-victoriabank'), $order_id, $this->format_price($amount, $currency), $this->get_method_title()));
                        $message = $this->get_test_message($message);
                        $this->log(
                            $message,
                            WC_Log_Levels::INFO,
                            array(
                                'bank_response' => $bank_response,
                                'bank_params' => $bank_params,
                                'check_result' => $check_result,
                                'check_transaction' => $check_transaction,
                            )
                        );

                        $order->add_order_note($message);
                        return true;

                    default:
                        $this->log(sprintf('Order #%1$s unknown bank response TRTYPE: %2$s', $order_id, $tr_type), WC_Log_Levels::ERROR);
                        break;
                }
            }

            /* translators: 1: Order ID, 2: Payment method title */
            $message = esc_html(sprintf(__('Order #%1$s payment transaction check failed via %2$s.', 'wc-victoriabank'), $order_id, $this->get_method_title()));
            $message = $this->get_test_message($message);
            $this->log(
                $message,
                WC_Log_Levels::ERROR,
                array(
                    'bank_response' => $bank_response,
                    'bank_params' => $bank_params,
                    'check_result' => $check_result,
                    'check_transaction' => $check_transaction,
                )
            );

            $order->add_order_note($message);
            return false;
        }

        public function callback_data_process()
        {
            $this->log_request(__FUNCTION__);

            // https://codex.wordpress.org/AJAX_in_Plugins
            // https://developer.wordpress.org/plugins/javascript/ajax/

            // https://developer.wordpress.org/reference/functions/check_ajax_referer/
            check_ajax_referer('callback_data_process');

            if (!self::is_wc_admin()) {
                // https://developer.wordpress.org/reference/functions/wp_die/
                $message = get_status_header_desc(WP_Http::FORBIDDEN);
                $this->log($message, WC_Log_Levels::ERROR);
                wp_send_json_error($message, WP_Http::FORBIDDEN);
                wp_die();
                return;
            }

            $callback_data = isset($_POST['callback_data']) ? sanitize_textarea_field(wp_unslash($_POST['callback_data'])) : '';
            if (!empty($callback_data)) {
                $vbdata = self::parse_response_post($callback_data);

                if (!empty($vbdata)) {
                    if ($this->is_available() && $this->enabled) {
                        $response = $this->process_response_data($vbdata);

                        if ($response) {
                            $message = __('Processed successfully', 'wc-victoriabank');
                            $this->log($message, WC_Log_Levels::INFO);
                            wp_send_json_success($message);
                        } else {
                            $message = __('Processing error', 'wc-victoriabank');
                            $this->log($message, WC_Log_Levels::ERROR);
                            wp_send_json_error($message);
                        }
                    } else {
                        /* translators: 1: Payment method title */
                        $message = sprintf(__('%1$s is not configured', 'wc-victoriabank'), $this->get_method_title());
                        $this->log($message, WC_Log_Levels::ERROR);
                        wp_send_json_error($message);
                    }
                } else {
                    $message = __('Invalid message', 'wc-victoriabank');
                    $this->log($message, WC_Log_Levels::ERROR);
                    wp_send_json_error($message);
                }
            } else {
                $message = __('Empty message', 'wc-victoriabank');
                $this->log($message, WC_Log_Levels::ERROR);
                wp_send_json_error($message);
            }

            wp_die();
        }

        protected function process_response_form(string $vbresponse)
        {
            $this->log(
                __FUNCTION__,
                WC_Log_Levels::DEBUG,
                array(
                    'vbresponse' => $vbresponse,
                    'backtrace' => true,
                )
            );

            $vbform = self::parse_response_form($vbresponse);
            if (empty($vbform)) {
                return false;
            }

            return $this->process_response_data($vbform);
        }

        protected function parse_response_form(string $vbformhtml)
        {
            return self::parse_response_regex($vbformhtml, '/<input.+name="(\w+)".+value="(.*?)"/i');
        }

        protected static function parse_response_post(string $vbpost)
        {
            return self::parse_response_regex($vbpost, '/^(\w+)=(.*)$/im');
        }

        protected static function parse_response_regex(string $vbresponse, string $regex)
        {
            $match_result = preg_match_all($regex, $vbresponse, $matches, PREG_SET_ORDER);
            if (empty($match_result)) {
                return false;
            }

            $vbdata = array();
            foreach ($matches as $match) {
                if (count($match) === 3) {
                    $vbdata[$match[1]] = $match[2];
                }
            }

            return $vbdata;
        }

        protected function mark_order_refunded(\WC_Order $order)
        {
            /* translators: 1: Payment method title */
            $message = esc_html(sprintf(__('Order fully refunded via %1$s.', 'wc-victoriabank'), $this->get_method_title()));
            $message = $this->get_test_message($message);

            //Mark order as refunded if not already set
            if (!$order->has_status('refunded')) {
                $order->update_status('refunded', $message);
            } else {
                $order->add_order_note($message);
            }
        }

        protected function generate_form(\WC_Order $order)
        {
            $order_id = strval($order->get_id());
            $order_total = $order->get_total();
            $order_currency = $order->get_currency();
            $order_description = $this->get_order_description($order);
            $order_email = $order->get_billing_email();
            $language = $this->get_language();

            $redirect_url = add_query_arg(self::VB_ORDER_ID, rawurlencode($order_id), $this->get_redirect_url());

            //Request payment authorization - redirects to the bank page
            $client = $this->init_victoriabank_client();
            $authorize_request = $client->generateOrderAuthorizeRequest($order_id, $order_total, $order_currency, $order_description, $order_email, $redirect_url, $language);
            $authorize_form = $client->generateHtmlForm($this->vb_base_url, $authorize_request);

            $this->log(
                __FUNCTION__,
                WC_Log_Levels::DEBUG,
                array(
                    'authorize_request' => $authorize_request,
                    'authorize_form' => $authorize_form,
                    'backtrace' => true,
                )
            );

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw HTML form.
            echo $authorize_form;
        }

        public function receipt_page(int $order_id)
        {
            $order = wc_get_order($order_id);
            $payment_method = $order->get_payment_method();

            try {
                if (self::MOD_ID === $payment_method) {
                    /* translators: 1: Order ID, 2: Payment method title */
                    $message = esc_html(sprintf(__('Order #%1$s payment initiated via %2$s.', 'wc-victoriabank'), $order_id, $this->get_method_title()));
                    $message = $this->get_test_message($message);
                    $this->log($message, WC_Log_Levels::INFO);
                    $order->add_order_note($message);

                    $this->generate_form($order);
                }
            } catch (Exception $ex) {
                $this->log(
                    $ex->getMessage(),
                    WC_Log_Levels::ERROR,
                    array(
                        'order_id' => $order_id,
                        'exception' => (string) $ex,
                        'backtrace' => true,
                    )
                );

                /* translators: 1: Order ID, 2: Payment method title */
                $message = esc_html(sprintf(__('Order #%1$s payment initiation failed via %2$s.', 'wc-victoriabank'), $order_id, $this->get_method_title()));
                $message = $this->get_test_message($message);
                $order->add_order_note($message);

                wc_add_notice($message, 'error');
                $this->settings_admin_notice();
            }
        }

        /**
         * @param  int    $order_id
         * @param  float  $amount
         * @param  string $reason
         */
        public function process_refund($order_id, $amount = null, $reason = '')
        {
            $this->log(
                __FUNCTION__,
                WC_Log_Levels::DEBUG,
                array(
                    'order_id' => $order_id,
                    'amount' => $amount,
                    'reason' => $reason,
                    'backtrace' => true,
                )
            );

            $order = wc_get_order($order_id);
            $order_currency = $order->get_currency();

            $rrn = strval($order->get_meta(strtolower(self::VB_RRN), true));
            $int_ref = strval($order->get_meta(strtolower(self::VB_INT_REF), true));
            if (empty($rrn)) {
                /* translators: 1: Order ID, 2: Meta field key */
                $message = esc_html(sprintf(__('Order #%1$s missing meta field %2$s.', 'wc-victoriabank'), $order_id, self::VB_RRN));
                return new WP_Error('order_rrn', $message);
            }
            if (empty($int_ref)) {
                /* translators: 1: Order ID, 2: Meta field key */
                $message = esc_html(sprintf(__('Order #%1$s missing meta field %2$s.', 'wc-victoriabank'), $order_id, self::VB_INT_REF));
                return new WP_Error('order_int_ref', $message);
            }

            $reversal_result = null;
            try {
                $client = $this->init_victoriabank_client();
                $reversal_result = $client->orderReverse(strval($order_id), $amount, $order_currency, $rrn, $int_ref);
            } catch (Exception $ex) {
                $this->log(
                    $ex->getMessage(),
                    WC_Log_Levels::ERROR,
                    array(
                        'order_id' => $order_id,
                        'amount' => $amount,
                        'reason' => $reason,
                        'reversal_result' => wp_json_encode($reversal_result),
                        'exception' => (string) $ex,
                        'backtrace' => true,
                    )
                );
            }

            if (empty($reversal_result)) {
                /* translators: 1: Order ID, 2: Refund amount, 3: Payment method title */
                $message = esc_html(sprintf(__('Order #%1$s refund of %2$s via %3$s failed.', 'wc-victoriabank'), $order_id, $this->format_price($amount, $order_currency), $this->get_method_title()));
                $message = $this->get_test_message($message);
                $this->log(
                    $message,
                    WC_Log_Levels::ERROR,
                    array(
                        'order_id' => $order_id,
                        'amount' => $amount,
                        'reason' => $reason,
                        'reversal_result' => wp_json_encode($reversal_result),
                    )
                );

                $order->add_order_note($message);
                return new WP_Error('process_refund', $message);
            }

            return true;
        }
        //endregion

        //region Order
        protected static function get_order_net_total(\WC_Order $order)
        {
            // https://github.com/woocommerce/woocommerce/issues/17795
            // https://github.com/woocommerce/woocommerce/pull/18196
            $total_refunded = 0;
            $order_refunds = $order->get_refunds();
            foreach ($order_refunds as $refund) {
                if ($refund->get_refunded_payment()) {
                    $total_refunded += $refund->get_amount();
                }
            }

            $order_total = $order->get_total();
            return $order_total - $total_refunded;
        }

        protected function format_price(float $price, string $currency)
        {
            $args = array(
                'currency' => $currency,
                'in_span' => false,
            );

            return html_entity_decode(wc_price($price, $args));
        }

        protected function get_order_description(\WC_Order $order)
        {
            $description = sprintf($this->order_template, $order->get_id());
            return (string) apply_filters('victoriabank_order_description', $description, $order);
        }
        //endregion

        //region Utility
        protected function get_test_message(string $message)
        {
            if ($this->testmode) {
                /* translators: 1: Original message */
                $message = esc_html(sprintf(__('TEST: %1$s', 'wc-victoriabank'), $message));
            }

            return $message;
        }

        protected function get_language()
        {
            $lang = get_locale();
            return substr($lang, 0, 2);
        }

        protected static function get_store_address()
        {
            $wc_countries = WC()->countries;
            $address = array(
                'address_1' => $wc_countries->get_base_address(),
                'address_2' => $wc_countries->get_base_address_2(),
                'city'      => $wc_countries->get_base_city(),
                'state'     => $wc_countries->get_base_state(),
                'postcode'  => $wc_countries->get_base_postcode(),
                'country'   => $wc_countries->get_base_country(),
            );

            return $wc_countries->get_formatted_address($address, ', ');
        }

        protected function get_callback_url()
        {
            // https://developer.woocommerce.com/docs/extensions/core-concepts/woocommerce-plugin-api-callback/
            $callback_url = WC()->api_request_url("wc_{$this->id}");
            return apply_filters('victoriabank_callback_url', $callback_url);
        }

        protected function get_redirect_url()
        {
            $redirect_url = WC()->api_request_url("wc_{$this->id}_redirect");
            return apply_filters('victoriabank_redirect_url', $redirect_url);
        }

        protected static function get_logs_url()
        {
            return add_query_arg(
                array(
                    'page'   => 'wc-status',
                    'tab'    => 'logs',
                    'source' => self::MOD_ID,
                ),
                admin_url('admin.php')
            );
        }

        public static function get_settings_url()
        {
            return add_query_arg(
                array(
                    'page'    => 'wc-settings',
                    'tab'     => 'checkout',
                    'section' => self::MOD_ID,
                ),
                admin_url('admin.php')
            );
        }

        protected function log(string $message, string $level = WC_Log_Levels::DEBUG, ?array $additional_context = null)
        {
            // https://developer.woocommerce.com/docs/best-practices/data-management/logging/
            // https://stackoverflow.com/questions/1423157/print-php-call-stack
            $log_context = array('source' => $this->id);
            if (!empty($additional_context)) {
                $log_context = array_merge($log_context, $additional_context);
            }

            $this->logger->log($level, $message, $log_context);
        }

        protected function log_request(string $source)
        {
            $this->log(
                $source,
                WC_Log_Levels::DEBUG,
                array(
                    'ip' => WC_Geolocation::get_ip_address(),
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Logging request data for debugging purposes.
                    'request' => $_REQUEST,
                    'server' => $_SERVER,
                    'backtrace' => true,
                )
            );
        }
        //endregion

        //region Admin
        public static function plugin_links(array $links)
        {
            $plugin_links = array(
                sprintf(
                    '<a href="%1$s">%2$s</a>',
                    esc_url(self::get_settings_url()),
                    esc_html__('Settings', 'wc-victoriabank')
                ),
            );

            return array_merge($plugin_links, $links);
        }

        public static function order_actions(array $actions, \WC_Order $order)
        {
            if (!$order->is_paid() || $order->get_payment_method() !== self::MOD_ID) {
                return $actions;
            }

            $transaction_type = strval($order->get_meta(self::MOD_TRANSACTION_TYPE, true));
            if (self::TRANSACTION_TYPE_AUTHORIZATION !== $transaction_type) {
                return $actions;
            }

            /* translators: 1: Payment method title */
            $actions['victoriabank_complete_transaction'] = esc_html(sprintf(__('Complete %1$s transaction', 'wc-victoriabank'), self::MOD_TITLE));
            return $actions;
        }

        public static function action_complete_transaction(\WC_Order $order)
        {
            $plugin = new self();
            return $plugin->complete_transaction($order);
        }
        //endregion

        //region WooCommerce
        public static function add_gateway(array $methods)
        {
            $methods[] = self::class;
            return $methods;
        }

        public static function is_wc_admin()
        {
            // https://developer.wordpress.org/reference/functions/current_user_can/
            return current_user_can('manage_woocommerce');
        }

        public static function email_order_meta_fields(array $fields, bool $sent_to_admin, \WC_Order $order)
        {
            if (!$order->is_paid() || $order->get_payment_method() !== self::MOD_ID) {
                return $fields;
            }

            $fields[self::VB_RRN] = array(
                'label' => __('Retrieval Reference Number (RRN)', 'wc-victoriabank'),
                'value' => strval($order->get_meta(strtolower(self::VB_RRN), true)),
            );

            $fields[self::VB_APPROVAL] = array(
                'label' => __('Authorization code', 'wc-victoriabank'),
                'value' => strval($order->get_meta(strtolower(self::VB_APPROVAL), true)),
            );

            $fields[self::VB_CARD] = array(
                'label' => __('Card number', 'wc-victoriabank'),
                'value' => strval($order->get_meta(strtolower(self::VB_CARD), true)),
            );

            return $fields;
        }
        //endregion
    }

    //Add gateway to WooCommerce
    add_filter('woocommerce_payment_gateways', array(WC_Gateway_Victoriabank::class, 'add_gateway'));

    //region Admin init
    if (is_admin()) {
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(WC_Gateway_Victoriabank::class, 'plugin_links'));

        //Add WooCommerce order actions
        add_filter('woocommerce_order_actions', array(WC_Gateway_Victoriabank::class, 'order_actions'), 10, 2);
        add_action('woocommerce_order_action_victoriabank_complete_transaction', array(WC_Gateway_Victoriabank::class, 'action_complete_transaction'));
    }
    //endregion

    //Add WooCommerce email templates actions
    add_filter('woocommerce_email_order_meta_fields', array(WC_Gateway_Victoriabank::class, 'email_order_meta_fields'), 10, 3);
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
            require_once plugin_dir_path(__FILE__) . 'wc-victoriabank-wbc.php';

            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function (\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                    $payment_method_registry->register(new WC_Gateway_Victoriabank_WBC());
                }
            );
        }
    }
);
//endregion
