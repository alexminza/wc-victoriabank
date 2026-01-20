<?php

declare(strict_types=1);

namespace AlexMinza\WC_Payment_Gateway;

defined('ABSPATH') || exit;

class WC_Payment_Gateway_Base extends \WC_Payment_Gateway
{
    const MOD_ID          = 'wc-payment-gateway-base';
    const MOD_TEXT_DOMAIN = self::MOD_ID;
    const MOD_VERSION     = null;

    const SUPPORTED_CURRENCIES = array();
    const ORDER_TEMPLATE       = 'Order #%1$s';

    const DEFAULT_TIMEOUT = 30; // seconds

    protected $testmode, $debug, $logger;
    protected $order_template;

    public function __construct()
    {
        $this->testmode = wc_string_to_bool($this->get_option('testmode', 'no'));
        $this->debug    = wc_string_to_bool($this->get_option('debug', 'no'));
        $this->logger   = new \WC_Logger(null, $this->debug ? \WC_Log_Levels::DEBUG : \WC_Log_Levels::INFO);

        $this->order_template = $this->get_option('order_template', static::ORDER_TEMPLATE);
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

    public function is_valid_for_use()
    {
        if (!in_array(get_woocommerce_currency(), static::SUPPORTED_CURRENCIES, true)) {
            return false;
        }

        return true;
    }

    //region Settings
    protected function check_settings()
    {
        return true;
    }

    protected function validate_settings()
    {
        if (!$this->is_valid_for_use()) {
            $this->add_error(
                sprintf(
                    '<strong>%1$s: %2$s</strong>. %3$s: %4$s',
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
                    esc_html__('Unsupported store currency', static::MOD_TEXT_DOMAIN),
                    esc_html(get_woocommerce_currency()),
                    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
                    esc_html__('Supported currencies', static::MOD_TEXT_DOMAIN),
                    esc_html(join(', ', static::SUPPORTED_CURRENCIES))
                )
            );

            return false;
        }

        return true;
    }

    /**
     * @link https://developer.woocommerce.com/docs/extensions/settings-and-config/implementing-settings/
     */
    protected function get_settings_field_label($key)
    {
        $form_fields = $this->get_form_fields();
        return $form_fields[$key]['title'];
    }

    public function validate_required_field($key, $value)
    {
        if (empty($value)) {
            /* translators: 1: Field label */
            $this->add_error(esc_html(sprintf(__('%1$s field must be set.', static::MOD_TEXT_DOMAIN), $this->get_settings_field_label($key)))); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
        }

        return $value;
    }

    /**
     * Get the HTML field ID for one or more field keys.
     *
     * @param string|string[] $key Field key or array of field keys.
     * @return string Comma-separated list of field IDs with # prefix.
     */
    protected function get_field_id($key): string
    {
        if (is_array($key)) {
            return implode(', ', array_map(array($this, 'get_field_id'), $key));
        }

        return '#' . $this->get_field_key($key);
    }
    //endregion

    //region Keys
    protected function validate_public_key(string $key_data)
    {
        $public_key_resource = openssl_pkey_get_public($key_data);

        if (false === $public_key_resource) {
            $this->log_openssl_errors(__FUNCTION__);

            return false;
        }

        if (PHP_VERSION_ID < 80000) {
            // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- PHP_VERSION_ID check performed before invocation.
            openssl_free_key($public_key_resource);
        }

        return true;
    }

    protected function validate_private_key(string $key_data, string $key_passphrase)
    {
        $private_key_resource = openssl_pkey_get_private($key_data, $key_passphrase);

        if (false === $private_key_resource) {
            $this->log_openssl_errors(__FUNCTION__);

            return false;
        }

        if (PHP_VERSION_ID < 80000) {
            // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- PHP_VERSION_ID check performed before invocation.
            openssl_free_key($private_key_resource);
        }

        return true;
    }

    protected function normalize_key_path(string $key_path)
    {
        $key_path = trim($key_path);

        if (empty($key_path) || strpos($key_path, 'file://') === 0 || strpos($key_path, '---') === 0) {
            return $key_path;
        }

        if (is_file($key_path)) {
            return "file://$key_path";
        }

        return $key_path;
    }
    //endregion

    //region Notice
    protected function logs_admin_website_notice()
    {
        if (self::is_wc_admin()) {
            $message = $this->get_logs_admin_message();
            wc_add_notice($message, 'error');
        }
    }

    protected function get_settings_admin_message()
    {
        /* translators: 1: Payment method title, 2: Plugin settings URL */
        $message = sprintf(wp_kses_post(__('%1$s is not properly configured. Verify plugin <a href="%2$s">Connection Settings</a>.', static::MOD_TEXT_DOMAIN)), esc_html($this->get_method_title()), esc_url(self::get_settings_url())); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
        return $message;
    }

    protected function get_logs_admin_message()
    {
        /* translators: 1: Payment method title, 2: Plugin settings URL */
        $message = sprintf(wp_kses_post(__('See <a href="%2$s">%1$s settings</a> page for log details and setup instructions.', static::MOD_TEXT_DOMAIN)), esc_html($this->get_method_title()), esc_url(self::get_settings_url())); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
        return $message;
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
                $total_refunded += floatval($refund->get_amount());
            }
        }

        $order_total = floatval($order->get_total());
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
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
        return wp_strip_all_tags(apply_filters("{$this->id}_order_description", $description, $order));
    }
    //endregion

    //region Utility
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

    protected static function is_wc_admin()
    {
        // https://developer.wordpress.org/reference/functions/current_user_can/
        return current_user_can('manage_woocommerce');
    }

    protected function get_test_message(string $message)
    {
        if ($this->testmode) {
            /* translators: 1: Original message */
            $message = esc_html(sprintf(__('TEST: %1$s', static::MOD_TEXT_DOMAIN), $message)); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
        }

        return $message;
    }

    protected function get_language()
    {
        $lang = get_locale();
        return substr($lang, 0, 2);
    }

    protected static function get_logs_url()
    {
        return add_query_arg(
            array(
                'page'   => 'wc-status',
                'tab'    => 'logs',
                'source' => static::MOD_ID,
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
                'section' => static::MOD_ID,
            ),
            admin_url('admin.php')
        );
    }

    protected function log(string $message, string $level = \WC_Log_Levels::DEBUG, ?array $additional_context = null)
    {
        // https://developer.woocommerce.com/docs/best-practices/data-management/logging/
        // https://stackoverflow.com/questions/1423157/print-php-call-stack
        $log_context = array('source' => $this->id);
        if (!empty($additional_context)) {
            $log_context = array_merge($log_context, $additional_context);
        }

        $this->logger->log($level, $message, $log_context);
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
            \WC_Log_Levels::ERROR,
            array(
                'openssl_errors' => $openssl_errors,
                'backtrace' => true,
            )
        );
    }

    protected function log_request(string $source)
    {
        $this->log(
            $source,
            \WC_Log_Levels::DEBUG,
            array(
                'ip' => \WC_Geolocation::get_ip_address(),
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Logging request data for debugging purposes.
                'request' => $_REQUEST,
                'server' => $_SERVER,
                'backtrace' => true,
            )
        );
    }

    protected static function get_guzzle_error_response_body(\Exception $exception)
    {
        // https://github.com/guzzle/guzzle/issues/2185
        if ($exception instanceof \GuzzleHttp\Command\Exception\CommandException) {
            $response = $exception->getResponse();

            if (!empty($response)) {
                return (string) $response->getBody();
            }
        }

        return null;
    }
    //endregion

    //region Admin
    public static function plugin_links(array $links)
    {
        $plugin_links = array(
            sprintf(
                '<a href="%1$s">%2$s</a>',
                esc_url(self::get_settings_url()),
                // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
                esc_html__('Settings', static::MOD_TEXT_DOMAIN)
            ),
        );

        return array_merge($plugin_links, $links);
    }
    //endregion

    //region WooCommerce
    public static function add_gateway(array $methods)
    {
        $methods[] = static::class;
        return $methods;
    }
    //endregion
}
