<?php

/**
 * @package wc-victoriabank
 */

declare(strict_types=1);

namespace AlexMinza\WC_Payment_Gateway;

defined('ABSPATH') || exit;

use Victoriabank\Victoriabank\VictoriabankClient;

class WC_Gateway_Victoriabank extends WC_Payment_Gateway_Base
{
    //region Constants
    const MOD_ID          = 'victoriabank';
    const MOD_TEXT_DOMAIN = 'wc-victoriabank';
    const MOD_PREFIX      = 'vb_';
    const MOD_TITLE       = 'Victoriabank';
    const MOD_VERSION     = '1.6.0';
    const MOD_PLUGIN_FILE = VICTORIABANK_MOD_PLUGIN_FILE;

    const SUPPORTED_CURRENCIES = array('MDL', 'EUR', 'USD');

    const TRANSACTION_TYPE_CHARGE        = 'charge';
    const TRANSACTION_TYPE_AUTHORIZATION = 'authorization';

    const LOGO_TYPE_BANK       = 'bank';
    const LOGO_TYPE_SYSTEMS    = 'systems';

    const MOD_TRANSACTION_TYPE = self::MOD_PREFIX . 'transaction_type';
    const MOD_PAYMENT_RECEIPT  = self::MOD_PREFIX . 'payment_receipt';
    const MOD_RRN              = self::MOD_PREFIX . 'rrn';
    const MOD_INT_REF          = self::MOD_PREFIX . 'int_ref';
    const MOD_APPROVAL         = self::MOD_PREFIX . 'approval';
    const MOD_CARD             = self::MOD_PREFIX . 'card';

    const MOD_ORDER_ID  = 'order_id';
    const MOD_ORDER_KEY = 'order_key';

    const MOD_ACTION_COMPLETE_TRANSACTION = self::MOD_PREFIX . 'complete_transaction';
    const MOD_ACTION_CHECK_PAYMENT        = self::MOD_PREFIX . 'check_payment';

    /**
     * Default API request timeout (seconds).
     */
    public const DEFAULT_TIMEOUT = 30;
    //endregion

    protected $transaction_type;
    protected $vb_base_url, $vb_merchant_id, $vb_merchant_terminal, $vb_merchant_name, $vb_merchant_url, $vb_merchant_address;
    protected $vb_private_key_pass, $vb_private_key, $vb_bank_public_key, $vb_signature_algo;

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

        parent::__construct();

        $this->icon             = self::get_logo_icon($this->get_option('logo_type', self::LOGO_TYPE_BANK));
        $this->transaction_type = $this->get_option('transaction_type', self::TRANSACTION_TYPE_CHARGE);

        // https://github.com/alexminza/victoriabank-sdk-php/blob/main/src/Victoriabank/VictoriabankClient.php
        $this->vb_base_url          = $this->testmode ? VictoriabankClient::TEST_BASE_URL : VictoriabankClient::DEFAULT_BASE_URL;
        $this->vb_merchant_id       = $this->get_option('vb_merchant_id');
        $this->vb_merchant_terminal = $this->get_option('vb_merchant_terminal');
        $this->vb_merchant_name     = $this->get_option('vb_merchant_name');
        $this->vb_merchant_url      = $this->get_option('vb_merchant_url');
        $this->vb_merchant_address  = $this->get_option('vb_merchant_address');

        $this->vb_bank_public_key   = $this->normalize_key_path($this->get_option('vb_bank_public_key'));
        $this->vb_private_key       = $this->normalize_key_path($this->get_option('vb_private_key'));
        $this->vb_private_key_pass  = $this->get_option('vb_private_key_pass');
        $this->vb_signature_algo    = $this->get_option('vb_signature_algo', VictoriabankClient::P_SIGN_HASH_ALGO_MD5);
        //endregion

        if (is_admin()) {
            add_action("woocommerce_update_options_payment_gateways_{$this->id}", array($this, 'process_admin_options'));
            add_action('wp_ajax_victoriabank_process_callback_data', array($this, 'process_callback_data'));
        }

        add_action("woocommerce_receipt_{$this->id}", array($this, 'receipt_page'));
        add_action("woocommerce_api_wc_{$this->id}", array($this, 'check_response'));
        add_action("woocommerce_api_wc_{$this->id}_redirect", array($this, 'check_redirect'));
    }

    public function init_form_fields()
    {
        $blog_info_name = get_bloginfo('name');
        $home_url = home_url();
        $store_address = WC()->mailer()->get_store_address();

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
                    '%1$s<br /><br /><a href="#" id="%4$s" class="button">%2$s</a> <a href="#" id="%5$s" class="button">%3$s</a>',
                    esc_html__('Use Basic settings to upload the key files received from the bank or configure manually using Advanced settings below.', 'wc-victoriabank'),
                    esc_html__('Basic settings&raquo;', 'wc-victoriabank'),
                    esc_html__('Advanced settings&raquo;', 'wc-victoriabank'),
                    $this->get_field_key('basic_settings'),
                    $this->get_field_key('advanced_settings')
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
                'title'       => __('Merchant private key', 'wc-victoriabank'),
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
            'vb_signature_algo' => array(
                'title'       => __('Signature algorithm', 'wc-victoriabank'),
                'type'        => 'select',
                'description' => __('P_SIGN signature algorithm provided by the bank.', 'wc-victoriabank'),
                'desc_tip'    => true,
                'default'     => VictoriabankClient::P_SIGN_HASH_ALGO_MD5,
                'class'       => 'wc-enhanced-select',
                'options'     => array(
                    VictoriabankClient::P_SIGN_HASH_ALGO_MD5    => strtoupper(VictoriabankClient::P_SIGN_HASH_ALGO_MD5),
                    VictoriabankClient::P_SIGN_HASH_ALGO_SHA256 => strtoupper(VictoriabankClient::P_SIGN_HASH_ALGO_SHA256),
                ),
            ),

            'payment_notification' => array(
                'title'       => __('Payment Notification', 'wc-victoriabank'),
                'type'        => 'title',
                'description' => sprintf(
                    '%1$s<br /><br /><b>%2$s:</b> <code>%3$s</code><br /><br /><a href="#" id="%5$s" class="button">%4$s</a>',
                    esc_html__('Provide this URL to the bank to enable online payment notifications.', 'wc-victoriabank'),
                    esc_html__('Callback URL', 'wc-victoriabank'),
                    esc_url($this->get_callback_url()),
                    esc_html__('Advanced&raquo;', 'wc-victoriabank'),
                    $this->get_field_key('payment_notification_advanced')
                ),
            ),
            'vb_callback_data'  => array(
                'title'       => __('Process callback data', 'wc-victoriabank'),
                'type'        => 'textarea',
                'description' => sprintf('<a href="#" id="%1$s" class="button">%2$s</a><span class="spinner" style="float: none;"></span>', $this->get_field_key('process_callback_data'), esc_html__('Process', 'wc-victoriabank')),
                'desc_tip'    => __('Manually process bank transaction response callback data received by email as part of the backup procedure.', 'wc-victoriabank'),
                'placeholder' => "TERMINAL=49812345\nTRTYPE=0\nORDER=000123\nAMOUNT=123.45\nCURRENCY=MDL\n...",
            ),
        );
    }

    protected static function get_logo_icon(string $logo_type)
    {
        switch ($logo_type) {
            case self::LOGO_TYPE_BANK:
                return plugins_url('/assets/img/victoriabank.png', self::MOD_PLUGIN_FILE);
            case self::LOGO_TYPE_SYSTEMS:
                return plugins_url('/assets/img/paymentsystems.png', self::MOD_PLUGIN_FILE);
        }

        return '';
    }

    public function admin_options()
    {
        $this->validate_settings();
        $this->display_errors();

        // https://developer.woocommerce.com/2025/11/19/deprecation-of-wc_enqueue_js-in-10-4/
        $script_handle = self::MOD_PREFIX . 'connection_settings';
        wp_register_script($script_handle, plugins_url('assets/js/connection_settings.js', self::MOD_PLUGIN_FILE), array('jquery'), self::MOD_VERSION, true);
        wp_enqueue_script($script_handle);
        wp_localize_script(
            $script_handle,
            $script_handle,
            array(
                'connection_basic_fields_ids' => $this->get_field_id(array('vb_bank_public_key_pem', 'vb_private_key_pem', 'vb_private_key_pass', 'vb_signature_algo')),
                'connection_advanced_fields_ids' => $this->get_field_id(array('vb_bank_public_key', 'vb_private_key', 'vb_private_key_pass', 'vb_signature_algo')),
                'notification_advanced_fields_ids' => $this->get_field_id('vb_callback_data'),
                'basic_settings_button_id' => $this->get_field_id('basic_settings'),
                'advanced_settings_button_id' => $this->get_field_id('advanced_settings'),
                'payment_notification_advanced_button_id' => $this->get_field_id('payment_notification_advanced'),
                'process_callback_data_button_id' => $this->get_field_id('process_callback_data'),
                'vb_callback_data_field_id' => $this->get_field_id('vb_callback_data'),
                'message' => __('Are you sure you want to process the entered bank transaction response callback data?', 'wc-victoriabank'),
                'action' => 'victoriabank_process_callback_data',
                'nonce' => wp_create_nonce('process_callback_data'),
            )
        );

        parent::admin_options();
    }

    public function process_admin_options()
    {
        unset($_POST[$this->get_field_key('vb_callback_data')]);

        $this->process_pem_setting('vb_bank_public_key_pem', 'vb_bank_public_key');
        $this->process_pem_setting('vb_private_key_pem', 'vb_private_key');

        return parent::process_admin_options();
    }

    //region Settings validation
    protected function check_settings()
    {
        return parent::check_settings()
            && !empty($this->vb_merchant_name)
            && !empty($this->vb_merchant_url)
            && !empty($this->vb_merchant_address)
            && !empty($this->vb_merchant_id)
            && !empty($this->vb_merchant_terminal)
            && !empty($this->vb_bank_public_key)
            && !empty($this->vb_private_key);
    }

    protected function validate_settings()
    {
        if (!parent::validate_settings()) {
            return false;
        }

        if (!$this->check_settings()) {
            /* translators: 1: Plugin installation instructions URL */
            $message_instructions = sprintf(__('See plugin documentation for <a href="%1$s" target="_blank">installation instructions</a>.', 'wc-victoriabank'), 'https://wordpress.org/plugins/wc-victoriabank/#installation');
            $this->add_error(sprintf('<strong>%1$s</strong>: %2$s. %3$s', esc_html__('Connection Settings', 'wc-victoriabank'), esc_html__('Not configured', 'wc-victoriabank'), wp_kses_post($message_instructions)));
            return false;
        } else {
            if (!$this->validate_public_key($this->vb_bank_public_key)) {
                /* translators: 1: Field label */
                $this->add_error(esc_html(sprintf(__('Invalid %1$s field.', 'wc-victoriabank'), $this->get_settings_field_label('vb_bank_public_key'))));
                return false;
            }

            if (!$this->validate_private_key($this->vb_private_key, $this->vb_private_key_pass)) {
                /* translators: 1: Field label, 2: Field label */
                $this->add_error(esc_html(sprintf(__('Invalid %1$s or %2$s fields.', 'wc-victoriabank'), $this->get_settings_field_label('vb_private_key'), $this->get_settings_field_label('vb_private_key_pass'))));
                return false;
            }
        }

        return true;
    }

    public function validate_order_template_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }

    public function validate_vb_merchant_name_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }

    public function validate_vb_merchant_url_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }

    public function validate_vb_merchant_address_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }

    public function validate_vb_merchant_id_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }

    public function validate_vb_merchant_terminal_field($key, $value)
    {
        return $this->validate_required_field($key, $value);
    }

    public function validate_vb_private_key_field($key, $value)
    {
        return $this->normalize_key_path($value);
    }

    public function validate_vb_bank_public_key_field($key, $value)
    {
        return $this->normalize_key_path($value);
    }
    //endregion

    //region Keys
    protected function process_pem_setting(string $pem_field_id, string $pem_target_field_id)
    {
        $pem_field_key = $this->get_field_key($pem_field_id);
        $pem_target_field_key = $this->get_field_key($pem_target_field_id);

        try {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by WooCommerce.
            if (isset($_FILES[$pem_field_key])) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- File validation is performed via is_uploaded_file and error check. Nonce verification is handled by WooCommerce.
                $pem_file = $_FILES[$pem_field_key];
                $tmp_name = $pem_file['tmp_name'];

                if (UPLOAD_ERR_OK === $pem_file['error'] && is_uploaded_file($tmp_name)) {
                    $wp_filesystem = self::get_wp_filesystem();
                    $pem_data = $wp_filesystem->get_contents($tmp_name);

                    if (false !== $pem_data) {
                        // Overwrite advanced setting value
                        $_POST[$pem_target_field_key] = $pem_data;
                    }
                }
            }
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'pem_field_id' => $pem_field_id,
                    'pem_target_field_id' => $pem_target_field_id,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }
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
            $log_file_name = \WC_Log_Handler_File::get_log_file_path($log_name);

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
            ->setSignatureAlgo($this->vb_signature_algo);

        return $client;
    }

    /**
     * @param int $order_id
     */
    public function process_payment($order_id)
    {
        // https://github.com/woocommerce/woocommerce/issues/48126#issuecomment-2180991020
        if (WC()->is_store_api_request() || is_ajax()) {
            $order = wc_get_order($order_id);

            return array(
                'result'   => 'success',
                'redirect' => $order->get_checkout_payment_url(true),
            );
        }

        $result = $this->receipt_page($order_id) ? 'pending' : 'failure';
        return array(
            'result' => $result,
        );
    }

    public function complete_transaction(\WC_Order $order)
    {
        $order_id = $order->get_id();

        $this->log(
            __FUNCTION__,
            \WC_Log_Levels::DEBUG,
            array(
                'order_id' => $order_id,
                'backtrace' => true,
            )
        );

        $order_total = floatval($order->get_remaining_refund_amount());
        $order_currency = $order->get_currency();

        $rrn = strval($order->get_meta(self::MOD_RRN, true));
        $int_ref = strval($order->get_meta(self::MOD_INT_REF, true));
        if (empty($rrn) || empty($int_ref)) {
            /* translators: 1: Order ID, 2: Meta field key, 3: Meta field key */
            $message = esc_html(sprintf(__('Order #%1$s missing meta fields %2$s, %3$s.', 'wc-victoriabank'), $order_id, self::MOD_RRN, self::MOD_INT_REF));
            return new \WP_Error('order_meta_fields', $message);
        }

        // Funds locked on bank side - transfer the product/service to the customer and request completion
        $completion_result = null;
        try {
            $client = $this->init_victoriabank_client();
            $completion_result = $client->orderComplete(strval($order_id), $order_total, $order_currency, $rrn, $int_ref);
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'order_id' => $order_id,
                    'order_total' => $order_total,
                    'response' => self::get_guzzle_error_response_body($ex),
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
                \WC_Log_Levels::ERROR,
                array(
                    'order_id' => $order_id,
                    'order_total' => $order_total,
                )
            );

            $order->add_order_note($message);
            return new \WP_Error('complete_transaction', $message);
        }

        return true;
    }

    public function check_payment(\WC_Order $order)
    {
        $order_id = $order->get_id();

        $this->log(
            __FUNCTION__,
            \WC_Log_Levels::DEBUG,
            array(
                'order_id' => $order_id,
                'backtrace' => true,
            )
        );

        $rrn = strval($order->get_meta(self::MOD_RRN, true));
        $tr_type = empty($rrn) ? VictoriabankClient::TRTYPE_AUTHORIZATION : VictoriabankClient::TRTYPE_SALES_COMPLETION;

        $check_result = null;
        try {
            $client = $this->init_victoriabank_client();
            $check_result = $client->orderCheck(strval($order_id), $tr_type);
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'order_id' => $order_id,
                    'tr_type' => $tr_type,
                    'response' => self::get_guzzle_error_response_body($ex),
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        if (!empty($check_result)) {
            $check_response = strval($check_result['body']);
            $check_data = self::parse_response_html_table($check_response);

            //NOTE: Victoriabank gateway responds with plain HTML table markup
            $check_data_values = is_array($check_data) ? array_values($check_data) : array();
            $check_data_values['ACTION'] = isset($check_data_values[0]) ? $check_data_values[0] : '';
            $check_data_values['TEXT'] = isset($check_data_values[2]) ? $check_data_values[2] : '';

            $transaction_status = $this->get_transaction_status_text($check_data_values);

            /* translators: 1: Order ID, 2: Payment method title, 3: Payment status */
            $message = esc_html(sprintf(__('Order #%1$s %2$s payment status: %3$s', 'wc-victoriabank'), $order_id, $this->get_method_title(), $transaction_status));
            $message = $this->get_test_message($message);
            \WC_Admin_Meta_Boxes::add_error($message);

            $this->log(
                $message,
                \WC_Log_Levels::DEBUG,
                array(
                    'order_id' => $order_id,
                    'check_data' => $check_data,
                )
            );

            $order->add_order_note($message);
            return;
        }

        /* translators: 1: Order ID */
        $message = esc_html(sprintf(__('Order #%1$s payment check failed.', 'wc-victoriabank'), $order_id));
        \WC_Admin_Meta_Boxes::add_error($message);
    }

    protected function check_transaction_order_data(\WC_Order $order, array $bank_response)
    {
        $payment_data_order_id = intval(VictoriabankClient::deNormalizeOrderId($bank_response['ORDER']));
        $payment_data_amount   = floatval($bank_response['AMOUNT']);
        $payment_data_currency = strval($bank_response['CURRENCY']);

        $order_id = $order->get_id();
        $order_total = floatval($order->get_total());
        $order_currency = $order->get_currency();

        $order_price = $this->format_price($order_total, $order_currency);
        $payment_data_price = $this->format_price($payment_data_amount, $payment_data_currency);

        if ($order_id !== $payment_data_order_id || $order_price !== $payment_data_price) {
            /* translators: 1: Payment data order ID, 2: Payment data price, 3: Order ID, 4: Order total price */
            $message = sprintf(__('Order payment data mismatch: Payment: #%1$s %2$s, Order: #%3$s %4$s.', 'wc-victoriabank'), $payment_data_order_id, $payment_data_price, $order_id, $order_price);
            $this->log($message, \WC_Log_Levels::ERROR);

            return false;
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
        $order_id = isset($_REQUEST[self::MOD_ORDER_ID]) ? absint(wp_unslash($_REQUEST[self::MOD_ORDER_ID])) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verification is done via order key check.
        $order_key = isset($_REQUEST[self::MOD_ORDER_KEY]) ? sanitize_text_field(wp_unslash($_REQUEST[self::MOD_ORDER_KEY])) : '';

        $order = wc_get_order($order_id);
        if (empty($order_id) || empty($order_key) || empty($order) || $order_key !== $order->get_order_key()) {
            /* translators: 1: Payment method title */
            $message = esc_html(sprintf(__('Invalid Order ID or Order Key received from %1$s.', 'wc-victoriabank'), $this->get_method_title()));
            $this->log(
                $message,
                \WC_Log_Levels::ERROR,
                array(
                    'order_id' => $order_id,
                    'order_key' => $order_key,
                )
            );

            wc_add_notice($message, 'error');
            $this->logs_admin_website_notice();

            wp_safe_redirect(wc_get_cart_url());
            return false;
        }

        if ($order->is_paid()) {
            /* translators: 1: Order ID, 2: Payment method title */
            $message = esc_html(sprintf(__('Order #%1$s paid successfully via %2$s.', 'wc-victoriabank'), $order_id, $this->get_method_title()));
            $this->log($message, \WC_Log_Levels::INFO);

            wc_add_notice($message, 'success');

            wp_safe_redirect($this->get_return_url($order));
            return true;
        } else {
            /* translators: 1: Order ID, 2: Payment method title */
            $message = esc_html(sprintf(__('Order #%1$s payment failed via %2$s.', 'wc-victoriabank'), $order_id, $this->get_method_title()));
            $this->log($message, \WC_Log_Levels::ERROR);

            wc_add_notice($message, 'error');
            $this->logs_admin_website_notice();

            wp_safe_redirect($order->get_checkout_payment_url());
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
            \WC_Log_Levels::DEBUG,
            array(
                'bank_response' => $bank_response,
                'backtrace' => true,
            )
        );

        $validate_result = null;
        try {
            $client = $this->init_victoriabank_client();
            $validate_result = $client->validateResponse($bank_response);
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'bank_response' => $bank_response,
                    'validate_result' => $validate_result,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        if (!$validate_result) {
            /* translators: 1: Payment method title */
            $message = esc_html(sprintf(__('%1$s payment notification callback validation failed.', 'wc-victoriabank'), $this->get_method_title()));
            $this->log(
                $message,
                \WC_Log_Levels::ERROR,
                array(
                    'bank_response' => $bank_response,
                    'validate_result' => $validate_result,
                    'backtrace' => true,
                )
            );

            return false;
        }

        //region Extract bank response params
        $terminal = strval($bank_response['TERMINAL'] ?? '');
        $tr_type  = strval($bank_response['TRTYPE'] ?? '');
        $order_id = VictoriabankClient::deNormalizeOrderId($bank_response['ORDER'] ?? '');
        $amount   = floatval($bank_response['AMOUNT'] ?? 0);
        $currency = strval($bank_response['CURRENCY'] ?? '');
        $action   = strval($bank_response['ACTION'] ?? '');
        $approval = strval($bank_response['APPROVAL'] ?? '');
        $rrn      = strval($bank_response['RRN'] ?? '');
        $int_ref  = strval($bank_response['INT_REF'] ?? '');
        $card     = strval($bank_response['CARD'] ?? '');
        //endregion

        //region Validate order
        if (empty($order_id)) {
            /* translators: 1: Payment method title */
            $message = esc_html(sprintf(__('Order ID not received from %1$s.', 'wc-victoriabank'), $this->get_method_title()));
            $this->log(
                $message,
                \WC_Log_Levels::ERROR,
                array(
                    'bank_response' => $bank_response,
                )
            );

            return false;
        }

        $order = wc_get_order($order_id);
        if (empty($order)) {
            /* translators: 1: Order ID, 2: Payment method title */
            $message = esc_html(sprintf(__('Order #%1$s not found as received from %2$s.', 'wc-victoriabank'), $order_id, $this->get_method_title()));
            $this->log(
                $message,
                \WC_Log_Levels::ERROR,
                array(
                    'bank_response' => $bank_response,
                )
            );

            return false;
        }
        //endregion

        //region Check TransResponse
        $check_transaction = ($terminal === $this->vb_merchant_terminal)
            && (VictoriabankClient::ACTION_SUCCESS === $action);
        //endregion

        if ($check_transaction) {
            switch ($tr_type) {
                case VictoriabankClient::TRTYPE_AUTHORIZATION:
                    if ($this->check_transaction_order_data($order, $bank_response)) {
                        //region Order already paid?
                        if ($order->is_paid()) {
                            /* translators: 1: Order ID */
                            $message = sprintf(__('Order #%1$s already fully paid.', 'wc-victoriabank'), $order_id);
                            $this->log($message, \WC_Log_Levels::WARNING);

                            return true;
                        }
                        //endregion

                        //region Complete order payment
                        // https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book
                        $order->update_meta_data(self::MOD_TRANSACTION_TYPE, $this->transaction_type);
                        $order->update_meta_data(self::MOD_PAYMENT_RECEIPT, http_build_query($bank_response));

                        $order->update_meta_data(self::MOD_RRN, $rrn);
                        $order->update_meta_data(self::MOD_INT_REF, $int_ref);
                        $order->update_meta_data(self::MOD_APPROVAL, $approval);
                        $order->update_meta_data(self::MOD_CARD, $card);

                        $order->save();

                        $order->payment_complete($rrn);
                        //endregion

                        /* translators: 1: Order ID, 2: Payment method title, 3: Payment data */
                        $message = esc_html(sprintf(__('Order #%1$s payment authorized via %2$s: %3$s', 'wc-victoriabank'), $order_id, $this->get_method_title(), $rrn));
                        $message = $this->get_test_message($message);
                        $this->log(
                            $message,
                            \WC_Log_Levels::INFO,
                            array(
                                'bank_response' => $bank_response,
                            )
                        );

                        $order->add_order_note($message);

                        if (self::TRANSACTION_TYPE_CHARGE === $this->transaction_type) {
                            $this->complete_transaction($order);
                        }

                        return true;
                    }
                    break;

                case VictoriabankClient::TRTYPE_SALES_COMPLETION:
                    /* translators: 1: Order ID, 2: Payment method title, 3: Payment data */
                    $message = esc_html(sprintf(__('Order #%1$s payment completed via %2$s: %3$s', 'wc-victoriabank'), $order_id, $this->get_method_title(), $rrn));
                    $message = $this->get_test_message($message);
                    $this->log(
                        $message,
                        \WC_Log_Levels::INFO,
                        array(
                            'bank_response' => $bank_response,
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
                        \WC_Log_Levels::INFO,
                        array(
                            'bank_response' => $bank_response,
                        )
                    );

                    $order->add_order_note($message);
                    return true;

                default:
                    $this->log(sprintf('Order #%1$s unknown bank response TRTYPE: %2$s', $order_id, $tr_type), \WC_Log_Levels::ERROR);
                    break;
            }
        }

        /* translators: 1: Order ID, 2: Payment method title, 3: Bank response text */
        $message = esc_html(sprintf(__('Order #%1$s payment transaction check failed via %2$s: %3$s', 'wc-victoriabank'), $order_id, $this->get_method_title(), $this->get_transaction_status_text($bank_response)));
        $message = $this->get_test_message($message);
        $this->log(
            $message,
            \WC_Log_Levels::ERROR,
            array(
                'bank_response' => $bank_response,
                'check_transaction' => $check_transaction,
            )
        );

        $order->add_order_note($message);
        return false;
    }

    public function process_callback_data()
    {
        $this->log_request(__FUNCTION__);

        // https://developer.wordpress.org/plugins/javascript/ajax/
        // https://developer.wordpress.org/reference/functions/check_ajax_referer/
        check_ajax_referer('process_callback_data');

        if (!self::is_wc_admin()) {
            $message = get_status_header_desc(\WP_Http::FORBIDDEN);
            $this->log($message, \WC_Log_Levels::ERROR);
            wp_send_json_error($message, \WP_Http::FORBIDDEN);
        }

        if (!$this->is_available()) {
            /* translators: 1: Payment method title */
            $message = sprintf(__('%1$s is not configured', 'wc-victoriabank'), $this->get_method_title());
            $this->log($message, \WC_Log_Levels::ERROR);
            wp_send_json_error($message);
        }

        $callback_data = isset($_POST['callback_data']) ? sanitize_textarea_field(wp_unslash($_POST['callback_data'])) : '';
        if (empty($callback_data)) {
            $message = __('Empty message', 'wc-victoriabank');
            $this->log($message, \WC_Log_Levels::ERROR);
            wp_send_json_error($message);
        }

        $vbdata = self::parse_response_post($callback_data);
        if (empty($vbdata)) {
            $message = __('Invalid message', 'wc-victoriabank');
            $this->log($message, \WC_Log_Levels::ERROR);
            wp_send_json_error($message);
        }

        $response = $this->process_response_data($vbdata);
        if ($response) {
            $message = __('Processed successfully', 'wc-victoriabank');
            $this->log($message, \WC_Log_Levels::INFO);
            wp_send_json_success($message);
        }

        $message = __('Processing error', 'wc-victoriabank');
        $this->log($message, \WC_Log_Levels::ERROR);
        wp_send_json_error($message);
    }

    protected function process_response_html_form(string $vbresponse)
    {
        $this->log(
            __FUNCTION__,
            \WC_Log_Levels::DEBUG,
            array(
                'vbresponse' => $vbresponse,
                'backtrace' => true,
            )
        );

        $vbform = self::parse_response_html_form($vbresponse);
        if (empty($vbform)) {
            return false;
        }

        return $this->process_response_data($vbform);
    }

    protected static function parse_response_html_form(string $vbhtml)
    {
        return self::parse_response_regex($vbhtml, '/<input.+?name=["\'](\w+?)["\'].+?value=["\'](.*?)["\']/i');
    }

    protected static function parse_response_html_table(string $vbhtml)
    {
        return self::parse_response_regex($vbhtml, '/<td>(.*?)<\/td>\s*?<td>(.*?)<\/td>/i');
    }

    protected static function parse_response_post(string $vbpost)
    {
        $vbpost = trim($vbpost);
        if (empty($vbpost)) {
            return null;
        }

        $vbdata = json_decode($vbpost, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($vbdata)) {
            return $vbdata;
        }

        // Transform key/value pairs to query string
        $vbpost = preg_replace('/\R+/', '&', $vbpost) ?? '';
        $vbdata = array();
        parse_str($vbpost, $vbdata);

        return $vbdata;
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
                $key = trim($match[1]);
                $value = trim($match[2]);
                $vbdata[$key] = $value;
            }
        }

        return $vbdata;
    }

    protected function generate_form(\WC_Order $order)
    {
        $order_id = strval($order->get_id());
        $order_total = floatval($order->get_total());
        $order_currency = $order->get_currency();
        $order_description = $this->get_order_description($order);
        $order_email = $order->get_billing_email();
        $language = $this->get_language();

        $redirect_url = add_query_arg(
            array(
                self::MOD_ORDER_ID  => $order_id,
                self::MOD_ORDER_KEY => $order->get_order_key(),
            ),
            $this->get_redirect_url()
        );

        $client = $this->init_victoriabank_client();
        $authorize_request = $client->generateOrderAuthorizeRequest($order_id, $order_total, $order_currency, $order_description, $order_email, $redirect_url, $language);
        $authorize_form = $client->generateHtmlForm($this->vb_base_url, $authorize_request);

        $this->log(
            __FUNCTION__,
            \WC_Log_Levels::DEBUG,
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
        try {
            /* translators: 1: Order ID, 2: Payment method title */
            $message = esc_html(sprintf(__('Order #%1$s payment initiated via %2$s.', 'wc-victoriabank'), $order_id, $this->get_method_title()));
            $message = $this->get_test_message($message);
            $this->log($message, \WC_Log_Levels::INFO);
            $order->add_order_note($message);

            $this->generate_form($order);
            return true;
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'order_id' => $order_id,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        /* translators: 1: Order ID, 2: Payment method title */
        $message = esc_html(sprintf(__('Order #%1$s payment initiation failed via %2$s.', 'wc-victoriabank'), $order_id, $this->get_method_title()));
        $message = $this->get_test_message($message);
        $order->add_order_note($message);

        wc_add_notice($message, 'error');
        $this->logs_admin_website_notice();

        wp_safe_redirect($order->get_checkout_payment_url());
        return false;
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
            \WC_Log_Levels::DEBUG,
            array(
                'order_id' => $order_id,
                'amount' => $amount,
                'reason' => $reason,
                'backtrace' => true,
            )
        );

        if (!$this->check_settings()) {
            $message = wp_strip_all_tags($this->get_settings_admin_message());
            return new \WP_Error('check_settings', $message);
        }

        $order = wc_get_order($order_id);
        $order_id = strval($order->get_id());
        $order_total = floatval($order->get_total());
        $order_currency = $order->get_currency();
        $amount = isset($amount) ? floatval($amount) : $order_total;

        $rrn = strval($order->get_meta(self::MOD_RRN, true));
        $int_ref = strval($order->get_meta(self::MOD_INT_REF, true));
        if (empty($rrn) || empty($int_ref)) {
            /* translators: 1: Order ID, 2: Meta field key, 3: Meta field key */
            $message = esc_html(sprintf(__('Order #%1$s missing meta fields %2$s, %3$s.', 'wc-victoriabank'), $order_id, self::MOD_RRN, self::MOD_INT_REF));
            return new \WP_Error('order_meta_fields', $message);
        }

        $reversal_result = null;
        try {
            $client = $this->init_victoriabank_client();
            $reversal_result = $client->orderReverse($order_id, $amount, $order_currency, $rrn, $int_ref);
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'order_id' => $order_id,
                    'amount' => $amount,
                    'reason' => $reason,
                    'response' => self::get_guzzle_error_response_body($ex),
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        $reversal_data = null;
        if (!empty($reversal_result)) {
            $reversal_response = strval($reversal_result['body']);
            $reversal_data = self::parse_response_html_form($reversal_response);

            if (!empty($reversal_data)) {
                $action = strval($reversal_data['ACTION']);
                if (VictoriabankClient::ACTION_SUCCESS === $action) {
                    /* translators: 1: Order ID, 2: Refund amount, 3: Payment method title, 4: Bank response text */
                    $message = esc_html(sprintf(__('Order #%1$s refund of %2$s initiated via %3$s: %4$s', 'wc-victoriabank'), $order_id, $this->format_price($amount, $order_currency), $this->get_method_title(), $this->get_transaction_status_text($reversal_data)));
                    $message = $this->get_test_message($message);
                    $this->log(
                        $message,
                        \WC_Log_Levels::INFO,
                        array(
                            'order_id' => $order_id,
                            'amount' => $amount,
                            'reason' => $reason,
                            'reversal_data' => $reversal_data,
                        )
                    );

                    $order->add_order_note($message);
                    return true;
                }
            }
        }

        /* translators: 1: Order ID, 2: Refund amount, 3: Payment method title, 4: Bank response text */
        $message = esc_html(sprintf(__('Order #%1$s refund of %2$s via %3$s failed: %4$s', 'wc-victoriabank'), $order_id, $this->format_price($amount, $order_currency), $this->get_method_title(), $this->get_transaction_status_text($reversal_data)));
        $message = $this->get_test_message($message);
        $this->log(
            $message,
            \WC_Log_Levels::ERROR,
            array(
                'order_id' => $order_id,
                'amount' => $amount,
                'reason' => $reason,
                'reversal_data' => $reversal_data,
            )
        );

        $order->add_order_note($message);
        return new \WP_Error('process_refund', $message);
    }

    private static function get_transaction_status_text(?array $vbdata)
    {
        if (empty($vbdata)) {
            return __('Unknown', 'wc-victoriabank');
        }

        $action = isset($vbdata['ACTION']) ? strval($vbdata['ACTION']) : '';
        $text   = isset($vbdata['TEXT']) ? strval($vbdata['TEXT']) : '';

        $action_status = '';
        switch ($action) {
            case VictoriabankClient::ACTION_SUCCESS:
                $action_status = __('Transaction successfully completed', 'wc-victoriabank');
                break;
            case VictoriabankClient::ACTION_DUPLICATE:
                $action_status = __('Duplicate transaction detected', 'wc-victoriabank');
                break;
            case VictoriabankClient::ACTION_DECLINED:
                $action_status = __('Transaction declined', 'wc-victoriabank');
                break;
            case VictoriabankClient::ACTION_FAULT:
                $action_status = __('Transaction processing fault', 'wc-victoriabank');
                break;
            default:
                $action_status = __('Unknown', 'wc-victoriabank');
                break;
        }

        return join(': ', array_filter(array($action_status, $text), 'strlen'));
    }
    //endregion

    //region Utility
    protected function get_callback_url()
    {
        // https://developer.woocommerce.com/docs/extensions/core-concepts/woocommerce-plugin-api-callback/
        $callback_url = WC()->api_request_url("wc_{$this->id}");
        return (string) apply_filters('victoriabank_callback_url', $callback_url);
    }

    protected function get_redirect_url()
    {
        $redirect_url = WC()->api_request_url("wc_{$this->id}_redirect");
        return (string) apply_filters('victoriabank_redirect_url', $redirect_url);
    }
    //endregion

    //region Admin
    public static function order_actions(array $actions, \WC_Order $order)
    {
        if ($order->get_payment_method() !== self::MOD_ID) {
            return $actions;
        }

        if ($order->is_paid()) {
            $transaction_type = strval($order->get_meta(self::MOD_TRANSACTION_TYPE, true));
            if (self::TRANSACTION_TYPE_AUTHORIZATION === $transaction_type) {
                /* translators: 1: Payment method title */
                $actions[self::MOD_ACTION_COMPLETE_TRANSACTION] = esc_html(sprintf(__('Complete %1$s transaction', 'wc-victoriabank'), self::MOD_TITLE));
            }
        }

        /* translators: 1: Payment method title */
        $actions[self::MOD_ACTION_CHECK_PAYMENT] = esc_html(sprintf(__('Check %1$s order payment', 'wc-victoriabank'), self::MOD_TITLE));

        return $actions;
    }

    public static function action_check_payment(\WC_Order $order)
    {
        /** @var WC_Gateway_Victoriabank $plugin */
        $plugin = self::get_payment_gateway_instance();
        return $plugin->check_payment($order);
    }

    public static function action_complete_transaction(\WC_Order $order)
    {
        /** @var WC_Gateway_Victoriabank $plugin */
        $plugin = self::get_payment_gateway_instance();
        return $plugin->complete_transaction($order);
    }
    //endregion

    //region WooCommerce
    public static function email_order_meta_fields(array $fields, bool $sent_to_admin, \WC_Order $order)
    {
        if (!$order->is_paid() || $order->get_payment_method() !== self::MOD_ID) {
            return $fields;
        }

        $fields[self::MOD_RRN] = array(
            'label' => __('Retrieval Reference Number (RRN)', 'wc-victoriabank'),
            'value' => strval($order->get_meta(self::MOD_RRN, true)),
        );

        $fields[self::MOD_APPROVAL] = array(
            'label' => __('Authorization code', 'wc-victoriabank'),
            'value' => strval($order->get_meta(self::MOD_APPROVAL, true)),
        );

        $fields[self::MOD_CARD] = array(
            'label' => __('Card number', 'wc-victoriabank'),
            'value' => strval($order->get_meta(self::MOD_CARD, true)),
        );

        return $fields;
    }
    //endregion
}
