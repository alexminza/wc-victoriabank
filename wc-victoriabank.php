<?php
/**
 * Plugin Name: WooCommerce Victoriabank Payment Gateway
 * Description: Accept Visa and Mastercard directly on your store with the Victoriabank payment gateway for WooCommerce.
 * Plugin URI: https://github.com/alexminza/wc-victoriabank
 * Version: 1.4.0-beta
 * Author: Alexander Minza
 * Author URI: https://profiles.wordpress.org/alexminza
 * Developer: Alexander Minza
 * Developer URI: https://profiles.wordpress.org/alexminza
 * Text Domain: wc-victoriabank
 * Domain Path: /languages
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires PHP: 7.0
 * Requires at least: 4.8
 * Tested up to: 6.5.3
 * WC requires at least: 3.3
 * WC tested up to: 8.9.1
 * Requires Plugins: woocommerce
 */

//Looking to contribute code to this plugin? Go ahead and fork the repository over at GitHub https://github.com/alexminza/wc-victoriabank
//This plugin is based on VictoriaBankGateway by Fruitware https://github.com/Fruitware/VictoriaBankGateway (https://packagist.org/packages/fruitware/victoria-bank-gateway)

if(!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

require_once(__DIR__ . '/vendor/autoload.php');

use Fruitware\VictoriaBankGateway\VictoriaBankGateway;
use Fruitware\VictoriaBankGateway\VictoriaBank\Response;

add_action('plugins_loaded', 'woocommerce_victoriabank_plugins_loaded', 0);

function woocommerce_victoriabank_plugins_loaded() {
	load_plugin_textdomain('wc-victoriabank', false, dirname(plugin_basename(__FILE__)) . '/languages');

	//https://docs.woocommerce.com/document/query-whether-woocommerce-is-activated/
	if(!class_exists('WooCommerce')) {
		add_action('admin_notices', 'woocommerce_victoriabank_missing_wc_notice');
		return;
	}

	woocommerce_victoriabank_init();
}

function woocommerce_victoriabank_missing_wc_notice() {
	echo sprintf('<div class="notice notice-error is-dismissible"><p>%1$s</p></div>', __('Victoriabank payment gateway requires WooCommerce to be installed and active.', 'wc-victoriabank'));
}

function woocommerce_victoriabank_init() {
	class WC_VictoriaBank extends WC_Payment_Gateway {
		#region Constants
		const MOD_ID          = 'victoriabank';
		const MOD_TITLE       = 'Victoriabank';
		const MOD_PREFIX      = 'vb_';
		const MOD_TEXT_DOMAIN = 'wc-victoriabank';

		const TRANSACTION_TYPE_CHARGE = 'charge';
		const TRANSACTION_TYPE_AUTHORIZATION = 'authorization';

		const LOGO_TYPE_BANK       = 'bank';
		const LOGO_TYPE_SYSTEMS    = 'systems';
		const LOGO_TYPE_NONE       = 'none';

		const MOD_TRANSACTION_TYPE = self::MOD_PREFIX . 'transaction_type';

		const SUPPORTED_CURRENCIES = ['MDL', 'EUR', 'USD'];
		const ORDER_TEMPLATE       = 'Order #%1$s';

		const VB_ORDER    = 'ORDER';
		const VB_ORDER_ID = 'order_id';

		const VB_RRN      = self::MOD_PREFIX . 'RRN';
		const VB_INT_REF  = self::MOD_PREFIX . 'INT_REF';
		const VB_APPROVAL = self::MOD_PREFIX . 'APPROVAL';
		const VB_CARD     = self::MOD_PREFIX . 'CARD';

		//e-Commerce Gateway merchant interface (CGI/WWW forms version)
		//Appendix A: P_SIGN creation/verification in the Merchant System
		//https://github.com/Fruitware/VictoriaBankGateway/blob/master/doc/e-Gateway_Merchant_CGI_2.1.pdf
		const VB_SIGNATURE_FIRST   = '0001';
		const VB_SIGNATURE_PREFIX  = '3020300C06082A864886F70D020505000410';
		const VB_SIGNATURE_PADDING = '00';
		#endregion

		protected $logo_type, $testmode, $debug, $logger, $transaction_type, $order_template;
		protected $vb_merchant_id, $vb_merchant_terminal, $vb_merchant_name, $vb_merchant_url, $vb_merchant_address;
		protected $vb_public_key_pem, $vb_bank_public_key_pem, $vb_private_key_pem, $vb_private_key_pass, $vb_public_key, $vb_private_key, $vb_bank_public_key;

		public function __construct() {
			$this->id                 = self::MOD_ID;
			$this->method_title       = self::MOD_TITLE;
			$this->method_description = 'WooCommerce Payment Gateway for Victoriabank';
			$this->has_fields         = false;
			$this->supports           = array('products', 'refunds');

			#region Initialize user set variables
			$this->enabled           = $this->get_option('enabled', 'no');
			$this->title             = $this->get_option('title', $this->method_title);
			$this->description       = $this->get_option('description');

			$this->logo_type         = $this->get_option('logo_type', self::LOGO_TYPE_BANK);
			$this->icon              = apply_filters('woocommerce_victoriabank_icon', self::get_logo_icon($this->logo_type));

			$this->testmode          = wc_string_to_bool($this->get_option('testmode', 'no'));
			$this->debug             = wc_string_to_bool($this->get_option('debug', 'no'));
			$this->logger            = new WC_Logger(null, $this->debug ? WC_Log_Levels::DEBUG : WC_Log_Levels::INFO);

			$this->transaction_type     = $this->get_option('transaction_type', self::TRANSACTION_TYPE_CHARGE);
			$this->order_template       = $this->get_option('order_template', self::ORDER_TEMPLATE);

			$this->vb_merchant_id       = $this->get_option('vb_merchant_id');
			$this->vb_merchant_terminal = $this->get_option('vb_merchant_terminal');
			$this->vb_merchant_name     = $this->get_option('vb_merchant_name');
			$this->vb_merchant_url      = $this->get_option('vb_merchant_url');
			$this->vb_merchant_address  = $this->get_option('vb_merchant_address');

			$this->vb_public_key_pem      = $this->get_option('vb_public_key_pem');
			$this->vb_bank_public_key_pem = $this->get_option('vb_bank_public_key_pem');
			$this->vb_private_key_pem     = $this->get_option('vb_private_key_pem');
			$this->vb_private_key_pass    = $this->get_option('vb_private_key_pass');

			$this->vb_public_key        = $this->get_option('vb_public_key');
			$this->vb_private_key       = $this->get_option('vb_private_key');
			$this->vb_bank_public_key   = $this->get_option('vb_bank_public_key');

			$this->init_form_fields();
			$this->init_settings();

			$this->initialize_keys();
			#endregion

			if(is_admin()) {
				//Save options
				add_action("woocommerce_update_options_payment_gateways_{$this->id}", array($this, 'process_admin_options'));
			}

			add_action("woocommerce_receipt_{$this->id}", array($this, 'receipt_page'));

			#region Payment listener/API hook
			add_action("woocommerce_api_wc_{$this->id}", array($this, 'check_response'));
			add_action("woocommerce_api_wc_{$this->id}_redirect", array($this, 'check_redirect'));
			#endregion
		}

		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'         => array(
					'title'       => __('Enable/Disable', self::MOD_TEXT_DOMAIN),
					'type'        => 'checkbox',
					'label'       => __('Enable this gateway', self::MOD_TEXT_DOMAIN),
					'default'     => 'yes'
				),
				'title'           => array(
					'title'       => __('Title', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'desc_tip'    => __('Payment method title that the customer will see during checkout.', self::MOD_TEXT_DOMAIN),
					'default'     => self::MOD_TITLE
				),
				'description'     => array(
					'title'       => __('Description', self::MOD_TEXT_DOMAIN),
					'type'        => 'textarea',
					'desc_tip'    => __('Payment method description that the customer will see during checkout.', self::MOD_TEXT_DOMAIN),
					'default'     => ''
				),
				'logo_type' => array(
					'title'       => __('Logo', self::MOD_TEXT_DOMAIN),
					'type'        => 'select',
					'class'       => 'wc-enhanced-select',
					'desc_tip'    => __('Payment method logo image that the customer will see during checkout.', self::MOD_TEXT_DOMAIN),
					'default'     => self::LOGO_TYPE_BANK,
					'options'     => array(
						self::LOGO_TYPE_BANK    => __('Bank logo', self::MOD_TEXT_DOMAIN),
						self::LOGO_TYPE_SYSTEMS => __('Payment systems logos', self::MOD_TEXT_DOMAIN),
						self::LOGO_TYPE_NONE    => __('No logo', self::MOD_TEXT_DOMAIN)
					)
				),

				'testmode'        => array(
					'title'       => __('Test mode', self::MOD_TEXT_DOMAIN),
					'type'        => 'checkbox',
					'label'       => __('Enabled', self::MOD_TEXT_DOMAIN),
					'desc_tip'    => __('Use Test or Live bank gateway to process the payments. Disable when ready to accept live payments.', self::MOD_TEXT_DOMAIN),
					'default'     => 'no'
				),
				'debug'           => array(
					'title'       => __('Debug mode', self::MOD_TEXT_DOMAIN),
					'type'        => 'checkbox',
					'label'       => __('Enable logging', self::MOD_TEXT_DOMAIN),
					'default'     => 'no',
					'description' => sprintf('<a href="%2$s">%1$s</a>', __('View logs', self::MOD_TEXT_DOMAIN), self::get_logs_url()),
					'desc_tip'    => __('Save debug messages to the WooCommerce System Status logs. Note: this may log personal information. Use this for debugging purposes only and delete the logs when finished.', self::MOD_TEXT_DOMAIN)
				),

				'transaction_type' => array(
					'title'       => __('Transaction type', self::MOD_TEXT_DOMAIN),
					'type'        => 'select',
					'class'       => 'wc-enhanced-select',
					'desc_tip'    => __('Select how transactions should be processed. Charge submits all transactions for settlement, Authorization simply authorizes the order total for capture later.', self::MOD_TEXT_DOMAIN),
					'default'     => self::TRANSACTION_TYPE_CHARGE,
					'options'     => array(
						self::TRANSACTION_TYPE_CHARGE        => __('Charge', self::MOD_TEXT_DOMAIN),
						self::TRANSACTION_TYPE_AUTHORIZATION => __('Authorization', self::MOD_TEXT_DOMAIN)
					)
				),
				'order_template'  => array(
					'title'       => __('Order description', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => __('Format: <code>%1$s</code> - Order ID, <code>%2$s</code> - Order items summary', self::MOD_TEXT_DOMAIN),
					'desc_tip'    => __('Order description that the customer will see on the bank payment page.', self::MOD_TEXT_DOMAIN),
					'default'     => self::ORDER_TEMPLATE
				),

				'merchant_settings' => array(
					'title'       => __('Merchant Data', self::MOD_TEXT_DOMAIN),
					'description' => __('Merchant information that the customer will see on the bank payment page.', self::MOD_TEXT_DOMAIN),
					'type'        => 'title'
				),
				'vb_merchant_name' => array(
					'title'       => __('Merchant name', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					"desc_tip"    => 'Latin symbols',
					'description' => $blogInfoName = get_bloginfo('name'),
					'default'     => $blogInfoName,
					'custom_attributes' => array(
						'maxlength' => '50'
					)
				),
				'vb_merchant_url' => array(
					'title'       => __('Merchant URL', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => $homeUrl = home_url(),
					'default'     => $homeUrl,
					'custom_attributes' => array(
						'maxlength' => '250'
					)
				),
				'vb_merchant_address' => array(
					'title'       => __('Merchant address', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => $storeAddress = self::get_store_address(),
					'default'     => $storeAddress,
					'custom_attributes' => array(
						'maxlength' => '250'
					)
				),
				'vb_merchant_id'  => array(
					'title'       => __('Card acceptor ID', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => 'Example: 498000049812345',
					'default'     => '',
					'custom_attributes' => array(
						'maxlength' => '15'
					)
				),
				'vb_merchant_terminal' => array(
					'title'       => __('Terminal ID', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => 'Example: 49812345',
					'default'     => '',
					'custom_attributes' => array(
						'maxlength' => '8'
					)
				),

				'connection_settings' => array(
					'title'       => __('Connection Settings', self::MOD_TEXT_DOMAIN),
					'description' => sprintf('%1$s<br /><br /><a href="#" id="woocommerce_victoriabank_basic_settings" class="button">%2$s</a>&nbsp;%3$s&nbsp;<a href="#" id="woocommerce_victoriabank_advanced_settings" class="button">%4$s</a>',
						__('Use Basic settings to upload the key files received from the bank or configure manually using Advanced settings below.', self::MOD_TEXT_DOMAIN),
						__('Basic settings&raquo;', self::MOD_TEXT_DOMAIN),
						__('or', self::MOD_TEXT_DOMAIN),
						__('Advanced settings&raquo;', self::MOD_TEXT_DOMAIN)),
					'type'        => 'title'
				),
				'vb_public_key_pem' => array(
					'title'       => __('Public key', self::MOD_TEXT_DOMAIN),
					'type'        => 'file',
					'description' => '<code>pubkey.pem</code>',
					'custom_attributes' => array(
						'accept' => '.pem'
					)
				),
				'vb_bank_public_key_pem' => array(
					'title'       => __('Bank public key', self::MOD_TEXT_DOMAIN),
					'type'        => 'file',
					'description' => '<code>victoria_pub.pem</code>',
					'custom_attributes' => array(
						'accept' => '.pem'
					)
				),
				'vb_private_key_pem' => array(
					'title'       => __('Private key', self::MOD_TEXT_DOMAIN),
					'type'        => 'file',
					'description' => '<code>key.pem</code>',
					'custom_attributes' => array(
						'accept' => '.pem'
					)
				),

				'vb_public_key'   => array(
					'title'       => __('Public key file', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => '<code>/path/to/pubkey.pem</code>',
					'default'     => ''
				),
				'vb_bank_public_key' => array(
					'title'       => __('Bank public key file', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => '<code>/path/to/victoria_pub.pem</code>',
					'default'     => ''
				),
				'vb_private_key'  => array(
					'title'       => __('Private key file', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => '<code>/path/to/key.pem</code>',
					'default'     => ''
				),
				'vb_private_key_pass' => array(
					'title'       => __('Private key passphrase', self::MOD_TEXT_DOMAIN),
					'type'        => 'password',
					'desc_tip'    => __('Leave empty if private key is not encrypted.', self::MOD_TEXT_DOMAIN),
					'placeholder' => __('Optional', self::MOD_TEXT_DOMAIN),
					'default'     => ''
				),

				'payment_notification' => array(
					'title'       => __('Payment Notification', self::MOD_TEXT_DOMAIN),
					'description' => sprintf('%1$s<br /><br /><b>%2$s:</b> <code>%3$s</code><br /><br /><a href="#" id="woocommerce_victoriabank_payment_notification_advanced" class="button">%4$s</a>',
						__('Provide this URL to the bank to enable online payment notifications.', self::MOD_TEXT_DOMAIN),
						__('Callback URL', self::MOD_TEXT_DOMAIN),
						esc_url($this->get_callback_url()),
						__('Advanced&raquo;', self::MOD_TEXT_DOMAIN)),
					'type'        => 'title'
				),
				'vb_callback_data'  => array(
					'title'       => __('Process callback data', self::MOD_TEXT_DOMAIN),
					'description' => '<a href="#" id="woocommerce_victoriabank_callback_data_process" class="button">Process</a>',
					'type'        => 'textarea',
					'desc_tip'    => __('Manually process bank transaction response callback data received by email as part of the backup procedure.', self::MOD_TEXT_DOMAIN),
					'placeholder' => __('Bank transaction response callback data', self::MOD_TEXT_DOMAIN),
				)
			);
		}

		protected static function get_logo_icon($logo_type) {
			$plugin_dir = plugin_dir_url(__FILE__);

			switch($logo_type) {
				case self::LOGO_TYPE_BANK:
					return "{$plugin_dir}assets/img/victoriabank.png";
					break;
				case self::LOGO_TYPE_SYSTEMS:
					return "{$plugin_dir}assets/img/paymentsystems.png";
					break;
				case self::LOGO_TYPE_NONE:
					return '';
					break;
			}

			return '';
		}

		public function is_valid_for_use() {
			if(!in_array(get_option('woocommerce_currency'), self::SUPPORTED_CURRENCIES)) {
				return false;
			}

			return true;
		}

		public function is_available() {
			if(!$this->is_valid_for_use())
				return false;

			if(!$this->check_settings())
				return false;

			return parent::is_available();
		}

		public function needs_setup() {
			return !$this->check_settings();
		}

		public function admin_options() {
			$this->validate_settings();
			$this->display_errors();

			wc_enqueue_js('
				jQuery(function() {
					var vb_connection_basic_fields_ids      = "#woocommerce_victoriabank_vb_public_key_pem, #woocommerce_victoriabank_vb_bank_public_key_pem, #woocommerce_victoriabank_vb_private_key_pem, #woocommerce_victoriabank_vb_private_key_pass";
					var vb_connection_advanced_fields_ids   = "#woocommerce_victoriabank_vb_public_key, #woocommerce_victoriabank_vb_bank_public_key, #woocommerce_victoriabank_vb_private_key, #woocommerce_victoriabank_vb_private_key_pass";
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
						if(!confirm("' . esc_js(__('Are you sure you want to process the entered bank transaction response callback data?', self::MOD_TEXT_DOMAIN)) . '"))
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
				});
			');

			parent::admin_options();
		}

		public function process_admin_options() {
			unset($_POST['woocommerce_victoriabank_vb_callback_data']);

			$this->process_pem_setting('woocommerce_victoriabank_vb_public_key_pem', $this->vb_public_key_pem, 'woocommerce_victoriabank_vb_public_key', 'pubkey.pem');
			$this->process_pem_setting('woocommerce_victoriabank_vb_bank_public_key_pem', $this->vb_bank_public_key_pem, 'woocommerce_victoriabank_vb_bank_public_key', 'victoria_pub.pem');
			$this->process_pem_setting('woocommerce_victoriabank_vb_private_key_pem', $this->vb_private_key_pem, 'woocommerce_victoriabank_vb_private_key', 'key.pem');

			return parent::process_admin_options();
		}

		protected function check_settings() {
			return !self::string_empty($this->vb_public_key)
				&& !self::string_empty($this->vb_bank_public_key)
				&& !self::string_empty($this->vb_private_key);
		}

		protected function validate_settings() {
			$validate_result = true;

			if(!$this->is_valid_for_use()) {
				$this->add_error(sprintf('<strong>%1$s: %2$s</strong>. %3$s: %4$s',
					__('Unsupported store currency', self::MOD_TEXT_DOMAIN),
					get_option('woocommerce_currency'),
					__('Supported currencies', self::MOD_TEXT_DOMAIN),
					join(', ', self::SUPPORTED_CURRENCIES)));

				$validate_result = false;
			}

			if(!$this->check_settings()) {
				$message_instructions = sprintf(__('See plugin documentation for <a href="%1$s" target="_blank">installation instructions</a>.', self::MOD_TEXT_DOMAIN), 'https://wordpress.org/plugins/wc-victoriabank/#installation');
				$this->add_error(sprintf('<strong>%1$s</strong>: %2$s. %3$s', __('Connection Settings', self::MOD_TEXT_DOMAIN), __('Not configured', self::MOD_TEXT_DOMAIN), $message_instructions));
				$validate_result = false;
			} else {
				$result = $this->validate_public_key($this->vb_public_key);
				if(!self::string_empty($result)) {
					$this->add_error(sprintf('<strong>%1$s</strong>: %2$s', __('Public key file', self::MOD_TEXT_DOMAIN), $result));
					$validate_result = false;
				}

				$result = $this->validate_public_key($this->vb_bank_public_key);
				if(!self::string_empty($result)) {
					$this->add_error(sprintf('<strong>%1$s</strong>: %2$s', __('Bank public key file', self::MOD_TEXT_DOMAIN), $result));
					$validate_result = false;
				}

				$result = $this->validate_private_key($this->vb_private_key, $this->vb_private_key_pass);
				if(!self::string_empty($result)) {
					$this->add_error(sprintf('<strong>%1$s</strong>: %2$s', __('Private key file', self::MOD_TEXT_DOMAIN), $result));
					$validate_result = false;
				}
			}

			if(ini_get('allow_url_fopen') != 1) {
				$this->add_error(sprintf('<strong>PHP %1$s</strong>: %2$s', 'allow_url_fopen', __('Current server settings do not allow web requests to the bank payment gateway. See <a href="https://www.php.net/manual/en/filesystem.configuration.php#ini.allow-url-fopen" target="_blank">PHP Runtime Configuration</a> for details.', self::MOD_TEXT_DOMAIN)));
				$validate_result = false;
			}

			return $validate_result;
		}

		protected function settings_admin_notice() {
			if(self::is_wc_admin()) {
				$message = sprintf(__('Please review the <a href="%1$s">payment method settings</a> page for log details and setup instructions.', self::MOD_TEXT_DOMAIN), self::get_settings_url());
				wc_add_notice($message, 'error');
			}
		}

		#region Keys
		protected function process_pem_setting($pemFieldId, $pemOptionValue, $pemTargetFieldId, $pemType) {
			try {
				if(array_key_exists($pemFieldId, $_FILES)) {
					$pemFile = $_FILES[$pemFieldId];
					$tmpName = $pemFile['tmp_name'];

					if($pemFile['error'] == UPLOAD_ERR_OK && is_uploaded_file($tmpName)) {
						$pemData = file_get_contents($tmpName);

						if($pemData !== false) {
							$result = self::save_temp_file($pemData, $pemType);

							if(!self::string_empty($result)) {
								//Overwrite advanced setting value
								$_POST[$pemTargetFieldId] = $result;
								//Save uploaded file to settings
								$_POST[$pemFieldId] = $pemData;

								return;
							}
						}
					}
				}
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
			}

			//Preserve existing value
			$_POST[$pemFieldId] = $pemOptionValue;
		}

		protected function initialize_keys() {
			$this->initialize_key($this->vb_public_key, $this->vb_public_key_pem, 'vb_public_key', 'pubkey.pem');
			$this->initialize_key($this->vb_bank_public_key, $this->vb_bank_public_key_pem, 'vb_bank_public_key', 'victoria_pub.pem');
			$this->initialize_key($this->vb_private_key, $this->vb_private_key_pem, 'vb_private_key', 'key.pem');
		}

		protected function initialize_key(&$pemFile, $pemData, $pemOptionName, $pemType) {
			try {
				if(!is_readable($pemFile)) {
					if(self::is_overwritable($pemFile)) {
						if(!self::string_empty($pemData)) {
							$result = self::save_temp_file($pemData, $pemType);

							if(!self::string_empty($result)) {
								$this->update_option($pemOptionName, $result);
								$pemFile = $result;
							}
						}
					}
				}
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
			}
		}

		protected function validate_public_key($keyFile) {
			try {
				$validateResult = $this->validate_file($keyFile);
				if(!self::string_empty($validateResult))
					return $validateResult;

				$keyData = file_get_contents($keyFile);
				$publicKey = openssl_pkey_get_public($keyData);

				if(false !== $publicKey) {
					//https://php.watch/versions/8.0/OpenSSL-resource
					//https://stackoverflow.com/questions/69559775/php-openssl-free-key-deprecated
					if(\PHP_VERSION_ID < 80000)
						openssl_pkey_free($publicKey);
				} else {
					$this->log_openssl_errors();
					return __('Invalid public key', self::MOD_TEXT_DOMAIN);
				}
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
				return __('Could not validate public key', self::MOD_TEXT_DOMAIN);
			}
		}

		protected function validate_private_key($keyFile, $keyPassphrase) {
			try {
				$validateResult = $this->validate_file($keyFile);
				if(!self::string_empty($validateResult))
					return $validateResult;

				$keyData = file_get_contents($keyFile);
				$privateKey = openssl_pkey_get_private($keyData, $keyPassphrase);

				if(false !== $privateKey) {
					//https://php.watch/versions/8.0/OpenSSL-resource
					//https://stackoverflow.com/questions/69559775/php-openssl-free-key-deprecated
					if(\PHP_VERSION_ID < 80000)
						openssl_pkey_free($privateKey);
				} else {
					$this->log_openssl_errors();
					return __('Invalid private key or wrong private key passphrase', self::MOD_TEXT_DOMAIN);
				}
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
				return __('Could not validate private key', self::MOD_TEXT_DOMAIN);
			}
		}

		protected function validate_file($file) {
			try {
				if(self::string_empty($file))
					return __('Invalid value', self::MOD_TEXT_DOMAIN);

				if(!file_exists($file))
					return __('File not found', self::MOD_TEXT_DOMAIN);

				if(!is_readable($file))
					return __('File not readable', self::MOD_TEXT_DOMAIN);
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
				return __('Could not validate file', self::MOD_TEXT_DOMAIN);
			}
		}

		protected function log_openssl_errors() {
			while($opensslError = openssl_error_string())
				$this->log($opensslError, WC_Log_Levels::ERROR);
		}

		protected static function save_temp_file($fileData, $fileSuffix = '') {
			//http://www.pathname.com/fhs/pub/fhs-2.3.html#TMPTEMPORARYFILES
			$tempFileName = sprintf('%1$s%2$s_', self::MOD_PREFIX, $fileSuffix);
			$temp_file = tempnam(get_temp_dir(),  $tempFileName);

			if(!$temp_file) {
				self::static_log(sprintf(__('Unable to create temporary file: %1$s', self::MOD_TEXT_DOMAIN), $temp_file), WC_Log_Levels::ERROR);
				return null;
			}

			if(false === file_put_contents($temp_file, $fileData)) {
				self::static_log(sprintf(__('Unable to save data to temporary file: %1$s', self::MOD_TEXT_DOMAIN), $temp_file), WC_Log_Levels::ERROR);
				return null;
			}

			return $temp_file;
		}

		protected static function is_temp_file($fileName) {
			$temp_dir = get_temp_dir();
			return strncmp($fileName, $temp_dir, strlen($temp_dir)) === 0;
		}

		protected static function is_overwritable($fileName) {
			return self::string_empty($fileName) || self::is_temp_file($fileName);
		}
		#endregion

		#region Payment
		protected function init_vb_client() {
			$victoriaBankGateway = new VictoriaBankGateway();

			$gateway_url = ($this->testmode ? 'https://ecomt.victoriabank.md/cgi-bin/cgi_link' : 'https://vb059.vb.md/cgi-bin/cgi_link');
			$sslVerify = !$this->testmode;

			//Set basic info
			$victoriaBankGateway
				->setGatewayUrl($gateway_url)
				->setSslVerify($sslVerify)
				->setMerchantId($this->vb_merchant_id)
				->setMerchantTerminal($this->vb_merchant_terminal)
				->setMerchantUrl($this->vb_merchant_url)
				->setMerchantName($this->vb_merchant_name)
				->setMerchantAddress($this->vb_merchant_address)
				->setTimezone(wc_timezone_string())
				->setDefaultLanguage($this->get_language());
				//->setCountryCode(WC()->countries->get_base_country())
				//->setDefaultCurrency(get_woocommerce_currency())
				//->setDebug($this->debug)

			//Set security options - provided by the bank
			$victoriaBankGateway->setSecurityOptions(
				self::VB_SIGNATURE_FIRST,
				self::VB_SIGNATURE_PREFIX,
				self::VB_SIGNATURE_PADDING,
				$this->vb_public_key,
				$this->vb_private_key,
				$this->vb_bank_public_key,
				$this->vb_private_key_pass);

			return $victoriaBankGateway;
		}

		public function process_payment($order_id) {
			if(!$this->check_settings()) {
				$message = sprintf(__('%1$s is not properly configured.', self::MOD_TEXT_DOMAIN), $this->method_title);

				wc_add_notice($message, 'error');
				$this->settings_admin_notice();

				return array(
					'result'   => 'failure',
					'messages' => $message
				);
			}

			if(is_ajax()) {
				$order = wc_get_order($order_id);

				return array(
					'result'   => 'success',
					'redirect' => $order->get_checkout_payment_url(true)
				);
			}

			$this->receipt_page($order_id);
		}

		public function complete_transaction($order_id, $order) {
			$this->log(sprintf('%1$s: OrderID=%2$s', __FUNCTION__, $order_id));

			$rrn = $order->get_meta(strtolower(self::VB_RRN), true);
			$intRef = $order->get_meta(strtolower(self::VB_INT_REF), true);
			$order_total = $this->get_order_net_total($order);
			$order_currency = $order->get_currency();

			//Funds locked on bank side - transfer the product/service to the customer and request completion
			$validate_result = false;
			try {
				$victoriaBankGateway = $this->init_vb_client();
				$completion_result = $victoriaBankGateway->requestCompletion($order_id, $order_total, $rrn, $intRef, $order_currency);
				$validate_result = self::validate_response_form($completion_result);
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
			}

			if(!$validate_result) {
				$message = sprintf(__('Payment completion via %1$s failed', self::MOD_TEXT_DOMAIN), $this->method_title);
				$message = $this->get_test_message($message);
				$order->add_order_note($message);

				return new WP_Error('error', $message);
			}

			return $validate_result;
		}

		public function refund_transaction($order_id, $order, $amount = null) {
			$this->log(sprintf('%1$s: OrderID=%2$s Amount=%3$s', __FUNCTION__, $order_id, $amount));

			$rrn = $order->get_meta(strtolower(self::VB_RRN), true);
			$intRef = $order->get_meta(strtolower(self::VB_INT_REF), true);
			$order_total = $order->get_total();
			$order_currency = $order->get_currency();

			if(!isset($amount)) {
				//Refund entirely if no amount is specified
				$amount = $order_total;
			}

			if($amount <= 0 || $amount > $order_total) {
				$message = sprintf(__('Invalid refund amount', self::MOD_TEXT_DOMAIN));
				$this->log($message, WC_Log_Levels::ERROR);

				return new WP_Error('error', $message);
			}

			$validate_result = false;
			try {
				$victoriaBankGateway = $this->init_vb_client();
				$reversal_result = $victoriaBankGateway->requestReversal($order_id, $amount, $rrn, $intRef, $order_currency);
				$validate_result = self::validate_response_form($reversal_result);
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
			}

			if(!$validate_result) {
				$message = sprintf(__('Refund of %1$s %2$s via %3$s failed', self::MOD_TEXT_DOMAIN), $amount, $order_currency, $this->method_title);
				$message = $this->get_test_message($message);
				$order->add_order_note($message);

				return new WP_Error('error', $message);
			}

			return $validate_result;
		}

		protected function check_transaction(WC_Order $order, $bankResponse) {
			$amount   = $bankResponse->{Response::AMOUNT};
			$currency = $bankResponse->{Response::CURRENCY};
			$trxType  = $bankResponse::TRX_TYPE;

			$order_total = $order->get_total();
			$order_currency = $order->get_currency();

			//Validate currency
			if(strtolower($currency) !== strtolower($order_currency))
				return false;

			//Validate amount
			if($amount <= 0)
				return false;

			if($trxType === VictoriaBankGateway::TRX_TYPE_REVERSAL)
				return $amount <= $order_total;

			return $amount == $order_total;
		}

		public function check_redirect() {
			$this->log_request(__FUNCTION__);

			//Received payment data from VB here instead of CallbackURL?
			if($_SERVER['REQUEST_METHOD'] === 'POST')
				$this->process_response_data($_POST);

			$order_id = $_REQUEST[self::VB_ORDER_ID];
			$order_id = wc_clean($order_id);

			if(self::string_empty($order_id)) {
				$message = sprintf(__('Payment verification failed: Order ID not received from %1$s.', self::MOD_TEXT_DOMAIN), $this->method_title);
				$this->log($message, WC_Log_Levels::ERROR);

				wc_add_notice($message, 'error');
				$this->settings_admin_notice();

				wp_safe_redirect(wc_get_cart_url());
				return false;
			}

			$order = wc_get_order($order_id);
			if(!$order) {
				$message = sprintf(__('Order #%1$s not found as received from %2$s.', self::MOD_TEXT_DOMAIN), $order_id, $this->method_title);
				$this->log($message, WC_Log_Levels::ERROR);

				wc_add_notice($message, 'error');
				$this->settings_admin_notice();

				wp_safe_redirect(wc_get_cart_url());
				return false;
			}

			if($order->is_paid()) {
				WC()->cart->empty_cart();

				$message = sprintf(__('Order #%1$s paid successfully via %2$s.', self::MOD_TEXT_DOMAIN), $order_id, $this->method_title);
				$this->log($message, WC_Log_Levels::INFO);

				wc_add_notice($message, 'success');

				wp_safe_redirect($this->get_return_url($order));
				return true;
			} else {
				$message = sprintf(__('Order #%1$s payment failed via %2$s.', self::MOD_TEXT_DOMAIN), $order_id, $this->method_title);
				$this->log($message, WC_Log_Levels::ERROR);

				wc_add_notice($message, 'error');
				$this->settings_admin_notice();

				wp_safe_redirect($order->get_checkout_payment_url()); //wc_get_checkout_url()
				return false;
			}
		}

		public function check_response() {
			$this->log_request(__FUNCTION__);

			if($_SERVER['REQUEST_METHOD'] === 'GET') {
				$message = __('This Callback URL works and should not be called directly.', self::MOD_TEXT_DOMAIN);

				wc_add_notice($message, 'notice');

				wp_safe_redirect(wc_get_cart_url());
				return false;
			}

			return $this->process_response_data($_POST);
		}

		public function process_response_data($vbdata) {
			$this->log(sprintf('%1$s: %2$s', __FUNCTION__, self::print_var($vbdata)));

			try {
				$victoriaBankGateway = $this->init_vb_client();
				$bankResponse = $victoriaBankGateway->getResponseObject($vbdata);
				$check_result = $bankResponse->isValid();
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
			}

			#region Extract bank response params
			$order_id  = VictoriaBankGateway::deNormalizeOrderId($bankResponse->{Response::ORDER});
			$amount    = $bankResponse->{Response::AMOUNT};
			$currency  = $bankResponse->{Response::CURRENCY};
			$approval  = $bankResponse->{Response::APPROVAL};
			$rrn       = $bankResponse->{Response::RRN};
			$intRef    = $bankResponse->{Response::INT_REF};
			$timeStamp = $bankResponse->{Response::TIMESTAMP};
			$text      = $bankResponse->{Response::TEXT};
			$bin       = $bankResponse->{Response::BIN};
			$card      = $bankResponse->{Response::CARD};

			$bankParams = array(
				'ORDER'     => $order_id,
				'AMOUNT'    => $amount,
				'CURRENCY'  => $currency,
				'TEXT'      => $text,
				'APPROVAL'  => $approval,
				'RRN'       => $rrn,
				'INT_REF'   => $intRef,
				'TIMESTAMP' => $timeStamp,
				'BIN'       => $bin,
				'CARD'      => $card
			);
			#endregion

			#region Validate order
			if(self::string_empty($order_id)) {
				$message = sprintf(__('Order ID not received from %1$s.', self::MOD_TEXT_DOMAIN), $this->method_title);
				$this->log($message, WC_Log_Levels::ERROR);
				return false;
			}

			$order = wc_get_order($order_id);
			if(!$order) {
				$message = sprintf(__('Order #%1$s not found as received from %2$s.', self::MOD_TEXT_DOMAIN), $order_id, $this->method_title);
				$this->log($message, WC_Log_Levels::ERROR);
				return false;
			}
			#endregion

			if($check_result && $this->check_transaction($order, $bankResponse)) {
				switch($bankResponse::TRX_TYPE) {
					case VictoriaBankGateway::TRX_TYPE_AUTHORIZATION:
						if($order->is_paid())
							return true; //Duplicate callback notification from the bank

						#region Update order payment metadata
						//https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book
						$order->add_meta_data(self::MOD_TRANSACTION_TYPE, $this->transaction_type, true);

						foreach($bankParams as $key => $value)
							$order->add_meta_data(strtolower(self::MOD_PREFIX . $key), $value, true);

						$order->save();
						#endregion

						$message = sprintf(__('Payment authorized via %1$s: %2$s', self::MOD_TEXT_DOMAIN), $this->method_title, http_build_query($bankParams));
						$message = $this->get_test_message($message);
						$this->log($message, WC_Log_Levels::INFO);
						$order->add_order_note($message);

						$this->mark_order_paid($order, $rrn);

						switch($this->transaction_type) {
							case self::TRANSACTION_TYPE_CHARGE:
								$this->complete_transaction($order_id, $order);
								break;

							case self::TRANSACTION_TYPE_AUTHORIZATION:
								break;

							default:
								$this->log(sprintf('Unknown transaction type: %1$s Order ID: %2$s', $this->transaction_type, $order_id), WC_Log_Levels::ERROR);
								break;
						}

						return true;
						break;

					case VictoriaBankGateway::TRX_TYPE_COMPLETION:
						//Funds successfully transferred on bank side
						$message = sprintf(__('Payment completed via %1$s: %2$s', self::MOD_TEXT_DOMAIN), $this->method_title, http_build_query($bankParams));
						$message = $this->get_test_message($message);
						$this->log($message, WC_Log_Levels::INFO);
						$order->add_order_note($message);

						$this->mark_order_paid($order, $rrn);

						return true;
						break;

					case VictoriaBankGateway::TRX_TYPE_REVERSAL:
						//Reversal successfully applied on bank side
						$message = sprintf(__('Refund of %1$s %2$s via %3$s approved: %4$s', self::MOD_TEXT_DOMAIN), $amount, $currency, $this->method_title, http_build_query($bankParams));
						$message = $this->get_test_message($message);
						$this->log($message, WC_Log_Levels::INFO);
						$order->add_order_note($message);

						if($order->get_total() == $order->get_total_refunded())
							$this->mark_order_refunded($order);

						return true;
						break;

					default:
						$this->log(sprintf('Unknown bank response TRX_TYPE: %1$s Order ID: %2$s', $bankResponse::TRX_TYPE, $order_id), WC_Log_Levels::ERROR);
						break;
				}
			}

			$this->log(sprintf(__('Payment transaction check failed for order #%1$s.', self::MOD_TEXT_DOMAIN), $order_id), WC_Log_Levels::ERROR);
			$this->log(self::print_var($bankResponse), WC_Log_Levels::ERROR);

			$message = sprintf(__('%1$s payment transaction check failed: %2$s', self::MOD_TEXT_DOMAIN), $this->method_title, join('; ', $bankResponse->getErrors()) . ' ' . http_build_query($bankParams));
			$message = $this->get_test_message($message);
			$order->add_order_note($message);
			return false;
		}

		public static function callback_data_process() {
			self::static_log_request(__FUNCTION__);

			//https://codex.wordpress.org/AJAX_in_Plugins
			//https://developer.wordpress.org/plugins/javascript/ajax/

			//https://developer.wordpress.org/reference/functions/check_ajax_referer/
			check_ajax_referer('callback_data_process');

			if(!self::is_wc_admin()) {
				//https://developer.wordpress.org/reference/functions/wp_die/
				$message = get_status_header_desc(WP_Http::FORBIDDEN);
				self::static_log($message, WC_Log_Levels::ERROR);
				wp_die($message, WP_Http::FORBIDDEN);
				return;
			}

			$callback_data = $_POST['callback_data'];
			if(!self::string_empty($callback_data)) {
				$vbdata = self::parse_response_post($callback_data);

				if(!empty($vbdata)) {
					$plugin = new self();
					if($plugin->is_available() && $plugin->enabled) {
						$response = $plugin->process_response_data($vbdata);

						if($response) {
							$message = sprintf(__('Processed successfully', self::MOD_TEXT_DOMAIN), self::MOD_TITLE);
							self::static_log($message, WC_Log_Levels::INFO);
							wp_send_json_success($message);
						} else {
							$message = sprintf(__('Processing error', self::MOD_TEXT_DOMAIN), self::MOD_TITLE);
							self::static_log($message, WC_Log_Levels::ERROR);
							wp_send_json_error($message);
						}
					} else {
						$message = sprintf(__('%1$s is not configured', self::MOD_TEXT_DOMAIN), self::MOD_TITLE);
						self::static_log($message, WC_Log_Levels::ERROR);
						wp_send_json_error($message);
					}
				} else {
					$message = sprintf(__('Invalid message', self::MOD_TEXT_DOMAIN), self::MOD_TITLE);
					self::static_log($message, WC_Log_Levels::ERROR);
					wp_send_json_error($message);
				}
			} else {
				$message = sprintf(__('Empty message', self::MOD_TEXT_DOMAIN), self::MOD_TITLE);
				self::static_log($message, WC_Log_Levels::ERROR);
				wp_send_json_error($message);
			}

			wp_die();
		}

		protected function validate_response_form($vbresponse) {
			$this->log(sprintf('%1$s: %2$s', __FUNCTION__, self::print_var($vbresponse)));

			if($vbresponse === false) {
				$error = error_get_last();
				if($error) {
					$message = $error['message'];

					$this->log($message, WC_Log_Levels::ERROR);
					$this->log(self::print_var($error));
				}

				return false;
			}

			return true;
		}

		protected function process_response_form($vbresponse) {
			$this->log(sprintf('%1$s: %2$s', __FUNCTION__, self::print_var($vbresponse)));

			$vbform = self::parse_response_form($vbresponse);
			if(empty($vbform))
				return false;

			return $this->process_response_data($vbform);
		}

		protected function parse_response_form($vbformhtml) {
			return self::parse_response_regex($vbformhtml, '/<input.+name="(\w+)".+value="(.*)"/i');
		}

		protected static function parse_response_post($vbpost) {
			return self::parse_response_regex($vbpost, '/^(\w+)=(.*)$/im');
		}

		protected static function parse_response_regex($vbresponse, $regex) {
			$matchResult = preg_match_all($regex, $vbresponse, $matches, PREG_SET_ORDER);
			if(empty($matchResult))
				return false;

			$vbdata = [];
			foreach($matches as $match)
				if(count($match) === 3)
					$vbdata[$match[1]] = $match[2];

			return $vbdata;
		}

		protected function mark_order_paid($order, $transaction_id) {
			if(!$order->is_paid())
				$order->payment_complete($transaction_id);
		}

		protected function mark_order_refunded($order) {
			$message = sprintf(__('Order fully refunded via %1$s.', self::MOD_TEXT_DOMAIN), $this->method_title);
			$message = $this->get_test_message($message);

			//Mark order as refunded if not already set
			if(!$order->has_status('refunded'))
				$order->update_status('refunded', $message);
			else
				$order->add_order_note($message);
		}

		protected function generate_form($order) {
			$order_id = $order->get_id();
			$order_total = $this->price_format($order->get_total());
			$order_currency = $order->get_currency();
			$order_description = $this->get_order_description($order);
			$order_email = $order->get_billing_email();
			$language = $this->get_language();

			$backRefUrl = add_query_arg(self::VB_ORDER_ID, urlencode($order_id), $this->get_redirect_url());

			//Request payment authorization - redirects to the banks page
			$victoriaBankGateway = $this->init_vb_client();
			$victoriaBankGateway->requestAuthorization(
				$order_id,
				$order_total,
				$backRefUrl,
				$order_currency,
				$order_description,
				$order_email,
				$language);
		}

		public function receipt_page($order_id) {
			try {
				$order = wc_get_order($order_id);
				$this->generate_form($order);
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);

				$message = sprintf(__('Payment initiation failed via %1$s.', self::MOD_TEXT_DOMAIN), $this->method_title);
				wc_add_notice($message, 'error');
				$this->settings_admin_notice();
			}
		}

		public function process_refund($order_id, $amount = null, $reason = '') {
			$order = wc_get_order($order_id);
			return $this->refund_transaction($order_id, $order, $amount);
		}
		#endregion

		#region Order
		protected function get_order_net_total($order) {
			//https://github.com/woocommerce/woocommerce/issues/17795
			//https://github.com/woocommerce/woocommerce/pull/18196
			$total_refunded = 0;
			if(method_exists(WC_Order_Refund::class, 'get_refunded_payment')) {
				$order_refunds = $order->get_refunds();
				foreach($order_refunds as $refund) {
					if($refund->get_refunded_payment())
						$total_refunded += $refund->get_amount();
				}
			}
			else
			{
				$total_refunded = $order->get_total_refunded();
			}

			$order_total = $order->get_total();
			return $order_total - $total_refunded;
		}

		protected function get_order_description($order) {
			return sprintf(__($this->order_template, self::MOD_TEXT_DOMAIN),
				$order->get_id(),
				$this->get_order_items_summary($order)
			);
		}

		protected function get_order_items_summary($order) {
			$items = $order->get_items();
			$items_names = array_map(function($item) { return $item->get_name(); }, $items);

			return join(', ', $items_names);
		}
		#endregion

		#region Utility
		protected function get_test_message($message) {
			if($this->testmode)
				$message = "TEST: $message";

			return $message;
		}

		protected function price_format($price) {
			$decimals = 2;

			return number_format($price, $decimals, '.', '');
		}

		protected function get_language() {
			$lang = get_locale();
			return substr($lang, 0, 2);
		}

		protected static function get_store_address() {
			$wc_countries = WC()->countries;
			$address = array(
				'address_1' => $wc_countries->get_base_address(),
				'address_2' => $wc_countries->get_base_address_2(),
				'city'      => $wc_countries->get_base_city(),
				'state'     => $wc_countries->get_base_state(),
				'postcode'  => $wc_countries->get_base_postcode(),
				'country'   => $wc_countries->get_base_country()
			);

			return $wc_countries->get_formatted_address($address, ', ');
		}

		protected static function get_client_ip() {
			return WC_Geolocation::get_ip_address();
		}

		protected function get_callback_url() {
			//https://developer.woo.com/docs/woocommerce-plugin-api-callbacks/
			return WC()->api_request_url("wc_{$this->id}");
		}

		protected function get_redirect_url() {
			return WC()->api_request_url("wc_{$this->id}_redirect");
		}

		protected static function get_logs_url() {
			return add_query_arg(
				array(
					'page'   => 'wc-status',
					'tab'    => 'logs',
					'source' => self::MOD_ID
				),
				admin_url('admin.php')
			);
		}

		public static function get_settings_url() {
			return add_query_arg(
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => self::MOD_ID
				),
				admin_url('admin.php')
			);
		}

		protected function log($message, $level = WC_Log_Levels::DEBUG) {
			//https://woocommerce.wordpress.com/2017/01/26/improved-logging-in-woocommerce-2-7/
			//https://stackoverflow.com/questions/1423157/print-php-call-stack
			$log_context = array('source' => self::MOD_ID);
			$this->logger->log($level, $message, $log_context);
		}

		protected static function static_log($message, $level = WC_Log_Levels::DEBUG) {
			$logger = wc_get_logger();
			$log_context = array('source' => self::MOD_ID);
			$logger->log($level, $message, $log_context);
		}

		protected function log_request($source) {
			$this->log(sprintf('%1$s: %2$s %3$s %4$s', $source, self::get_client_ip(), $_SERVER['REQUEST_METHOD'], self::print_var($_REQUEST)));
		}

		protected static function static_log_request($source) {
			self::static_log(sprintf('%1$s: %2$s %3$s %4$s', $source, self::get_client_ip(), $_SERVER['REQUEST_METHOD'], self::print_var($_REQUEST)));
		}

		protected static function print_var($var) {
			//https://docs.woocommerce.com/wc-apidocs/function-wc_print_r.html
			return wc_print_r($var, true);
		}

		protected static function string_empty($string) {
			return is_null($string) || strlen($string) === 0;
		}
		#endregion

		#region Admin
		public static function plugin_links($links) {
			$plugin_links = array(
				sprintf('<a href="%1$s">%2$s</a>', esc_url(self::get_settings_url()), __('Settings', self::MOD_TEXT_DOMAIN))
			);

			return array_merge($plugin_links, $links);
		}

		public static function order_actions($actions) {
			global $theorder;
			if(!$theorder->is_paid() || $theorder->get_payment_method() !== self::MOD_ID) {
				return $actions;
			}

			$transaction_type = $theorder->get_meta(self::MOD_TRANSACTION_TYPE, true);
			if($transaction_type !== self::TRANSACTION_TYPE_AUTHORIZATION) {
				return $actions;
			}

			$actions['victoriabank_complete_transaction'] = sprintf(__('Complete %1$s transaction', self::MOD_TEXT_DOMAIN), self::MOD_TITLE);
			return $actions;
		}

		public static function action_complete_transaction($order) {
			$order_id = $order->get_id();

			$plugin = new self();
			return $plugin->complete_transaction($order_id, $order);
		}
		#endregion

		#region WooCommerce
		public static function add_gateway($methods) {
			$methods[] = self::class;
			return $methods;
		}

		public static function is_wc_admin() {
			//https://developer.wordpress.org/reference/functions/current_user_can/
			return current_user_can('manage_woocommerce');
		}

		public static function email_order_meta_fields($fields, $sent_to_admin, $order) {
			if(!$order->is_paid() || $order->get_payment_method() !== self::MOD_ID) {
				return $fields;
			}

			$fields[self::VB_RRN] = array(
				'label' => __('Retrieval Reference Number (RRN)'),
				'value' => $order->get_meta(strtolower(self::VB_RRN), true),
			);

			$fields[self::VB_APPROVAL] = array(
				'label' => __('Authorization code'),
				'value' => $order->get_meta(strtolower(self::VB_APPROVAL), true),
			);

			$fields[self::VB_CARD] = array(
				'label' => __('Card number'),
				'value' => $order->get_meta(strtolower(self::VB_CARD), true),
			);

			return $fields;
		}
		#endregion
	}

	//Add gateway to WooCommerce
	add_filter('woocommerce_payment_gateways', array(WC_VictoriaBank::class, 'add_gateway'));

	#region Admin init
	if(is_admin()) {
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(WC_VictoriaBank::class, 'plugin_links'));

		//Add WooCommerce order actions
		add_filter('woocommerce_order_actions', array(WC_VictoriaBank::class, 'order_actions'));
		add_action('woocommerce_order_action_victoriabank_complete_transaction', array(WC_VictoriaBank::class, 'action_complete_transaction'));

		add_action('wp_ajax_victoriabank_callback_data_process', array(WC_VictoriaBank::class, 'callback_data_process'));
	}
	#endregion

	//Add WooCommerce email templates actions
	add_filter('woocommerce_email_order_meta_fields', array(WC_VictoriaBank::class, 'email_order_meta_fields'), 10, 3);
}

#region Declare WooCommerce compatibility
add_action('before_woocommerce_init', function() {
	if(class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		//WooCommerce HPOS compatibility
		//https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book#declaring-extension-incompatibility
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);

		//WooCommerce Cart Checkout Blocks compatibility
		//https://github.com/woocommerce/woocommerce/pull/36426
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
	}
});
#endregion

#region Register WooCommerce Blocks payment method type
add_action('woocommerce_blocks_loaded', function() {
	if(class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
		require_once plugin_dir_path(__FILE__) . 'wc-victoriabank-wbc.php';

		add_action('woocommerce_blocks_payment_method_type_registration',
			function(\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
				$payment_method_registry->register(new WC_VictoriaBank_WBC());
			}
		);
	}
});
#endregion
