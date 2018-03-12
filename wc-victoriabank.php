<?php
/**
 * Plugin Name: WooCommerce VictoriaBank Payment Gateway
 * Description: WooCommerce Payment Gateway for VictoriaBank
 * Plugin URI: https://github.com/alexminza/wc-victoriabank
 * Version: 1.0
 * Author: Alexander Minza
 * Author URI: https://profiles.wordpress.org/alexminza
 * Developer: Alexander Minza
 * Developer URI: https://profiles.wordpress.org/alexminza
 * Text Domain: wc-victoriabank
 * Domain Path: /languages
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least: 4.8
 * Tested up to: 4.9.4
 * WC requires at least: 3.2
 * WC tested up to: 3.3.3
 */

//Looking to contribute code to this plugin? Go ahead and fork the repository over at GitHub https://github.com/alexminza/wc-victoriabank
//This plugin is based on VictoriaBankGateway by Fruitware https://github.com/Fruitware/VictoriaBankGateway (https://packagist.org/packages/fruitware/victoria-bank-gateway)

if(!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

require_once(__DIR__ . '/vendor/autoload.php');

use Fruitware\VictoriaBankGateway\VictoriaBankGateway;
use Fruitware\VictoriaBankGateway\VictoriaBank\Exception;
use Fruitware\VictoriaBankGateway\VictoriaBank\Request;
use Fruitware\VictoriaBankGateway\VictoriaBank\Response;
use Fruitware\VictoriaBankGateway\VictoriaBank\AuthorizationResponse;

add_action('plugins_loaded', 'woocommerce_victoriabank_init', 0);

function woocommerce_victoriabank_init() {
	if(!class_exists(WC_Payment_Gateway::class))
		return;

	load_plugin_textdomain('wc-victoriabank', false, dirname(plugin_basename(__FILE__)) . '/languages');

	class WC_VictoriaBank extends WC_Payment_Gateway {
		protected $logger;

		//region Constants
		const MOD_ID          = 'victoriabank';
		const MOD_TITLE       = 'VictoriaBank';
		const MOD_PREFIX      = 'VB_';
		const MOD_TEXT_DOMAIN = 'wc-victoriabank';

		//Sends through sale and request for funds to be charged to cardholder's credit card.
		const TRANSACTION_TYPE_CHARGE = 'charge';
		//Sends through a request for funds to be "reserved" on the cardholder's credit card. Reservation times are determined by cardholder's bank.
		const TRANSACTION_TYPE_AUTHORIZATION = 'authorization';

		const MOD_TRANSACTION_TYPE = self::MOD_PREFIX . 'transaction_type';
		const ORDER_TEMPLATE = 'Order #%1$s';

		const VB_ORDER    = 'ORDER';
		const VB_ORDER_ID = 'order_id';

		const VB_RRN      = self::MOD_PREFIX . 'RRN';
		const VB_INT_REF  = self::MOD_PREFIX . 'INT_REF';
		const VB_APPROVAL = self::MOD_PREFIX . 'APPROVAL';
		const VB_CARD     = self::MOD_PREFIX . 'CARD';
		//endregion

		public function __construct() {
			$plugin_dir = plugin_dir_url(__FILE__);

			$this->logger = wc_get_logger();

			$this->id                 = self::MOD_ID;
			$this->method_title       = self::MOD_TITLE;
			$this->method_description = 'WooCommerce Payment Gateway for VictoriaBank';
			$this->icon               = apply_filters('woocommerce_victoriabank_icon', '' . $plugin_dir . '/assets/img/victoriabank.png');
			$this->has_fields         = false;
			$this->supports           = array('products', 'refunds');

			$this->init_form_fields();
			$this->init_settings();

			//region Define user set variables
			$this->enabled           = $this->get_option('enabled');
			$this->title             = $this->get_option('title');
			$this->description       = $this->get_option('description');

			$this->debug             = 'yes' === $this->get_option('debug', 'no');

			$this->log_context = array(
				'source' => $this->id
			);
			$this->log_threshold = $this->debug ? WC_Log_Levels::DEBUG : WC_Log_Levels::INFO;
			$this->logger = new WC_Logger(null, $this->log_threshold);

			$this->transaction_type     = $this->get_option('transaction_type', self::TRANSACTION_TYPE_CHARGE);
			$this->transaction_auto     = 'yes' === $this->get_option('transaction_auto', 'yes');

			$this->order_template       = $this->get_option('order_template', self::ORDER_TEMPLATE);

			$this->vb_merchant_id       = $this->get_option('vb_merchant_id');
			$this->vb_merchant_terminal = $this->get_option('vb_merchant_terminal');
			$this->vb_merchant_name     = $this->get_option('vb_merchant_name');
			$this->vb_merchant_url      = $this->get_option('vb_merchant_url');
			$this->vb_merchant_address  = $this->get_option('vb_merchant_address');

			//e-Gateway_Merchant_CGI_2.1.pdf
			//e-Commerce Gateway merchant interface (CGI/WWW forms version)
			//Appendix A: P_SIGN creation/verification in the Merchant System
			$this->vb_signature_first   = '0001';
			$this->vb_signature_prefix  = '3020300C06082A864886F70D020505000410';
			$this->vb_signature_padding = '00';

			$this->vb_public_key        = $this->get_option('vb_public_key');
			$this->vb_private_key       = $this->get_option('vb_private_key');
			$this->vb_private_key_pass  = $this->get_option('vb_private_key_pass');
			$this->vb_bank_public_key   = $this->get_option('vb_bank_public_key');
			//endregion

			if(is_admin()) {
				//Save options
				add_action('woocommerce_update_options_payment_gateways_' . strtolower($this->id), array($this, 'process_admin_options'));
			}

			add_action('woocommerce_receipt_' . strtolower($this->id), array($this, 'receipt_page'));

			if($this->transaction_auto) {
				add_filter('woocommerce_order_status_completed', array($this, 'order_status_completed'));
				add_filter('woocommerce_order_status_cancelled', array($this, 'order_status_cancelled'));
			}

			//region Payment listener/API hook
			add_action('woocommerce_api_wc_' . strtolower($this->id), array($this, 'check_response'));
			add_action('woocommerce_api_wc_' . strtolower($this->id) . '_redirect', array($this, 'check_redirect'));
			//endregion

			if(!$this->is_valid_for_use()) {
				$this->enabled = false;
			}
		}

		/**
		 * Initialize Gateway Settings Form Fields
		 */
		function init_form_fields() {
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

				'debug'           => array(
					'title'       => __('Debug mode', self::MOD_TEXT_DOMAIN),
					'type'        => 'checkbox',
					'label'       => __('Enable logging', self::MOD_TEXT_DOMAIN),
					'description' => sprintf(__('Callback URL: <code>%1$s</code>', self::MOD_TEXT_DOMAIN), $this->get_callback_url()) . '<br />'
										. sprintf(__('Redirect URL: <code>%1$s</code>', self::MOD_TEXT_DOMAIN), $this->get_redirect_url()),
					'default'     => 'no'
				),

				'transaction_type' => array(
					'title'       => __('Transaction type', self::MOD_TEXT_DOMAIN),
					'type'        => 'select',
					'desc_tip'    => __('Select how transactions should be processed. Charge submits all transactions for settlement, Authorization simply authorizes the order total for capture later.', self::MOD_TEXT_DOMAIN),
					'default'     => self::TRANSACTION_TYPE_CHARGE,
					'options'     => array(
						self::TRANSACTION_TYPE_CHARGE        => __('Charge', self::MOD_TEXT_DOMAIN),
						self::TRANSACTION_TYPE_AUTHORIZATION => __('Authorization', self::MOD_TEXT_DOMAIN),
					),
				),
				'transaction_auto' => array(
					'title'       => __('Transaction auto', self::MOD_TEXT_DOMAIN),
					'type'        => 'checkbox',
					//'label'       => __('Enabled', self::MOD_TEXT_DOMAIN),
					'label'       => __('Automatically complete/reverse bank transactions when order status changes', self::MOD_TEXT_DOMAIN),
					'default'     => 'yes'
				),
				'order_template'  => array(
					'title'       => __('Order description', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => __('Format: %1$s - Order ID, %2$s - Order items summary', self::MOD_TEXT_DOMAIN),
					'default'     => self::ORDER_TEMPLATE
				),

				'connection_settings' => array(
					'title'       => __('Connection Settings', self::MOD_TEXT_DOMAIN),
					'type'        => 'title',
					'description' => __('Merchant security connection settings provided by the bank.', self::MOD_TEXT_DOMAIN)
				),

				'vb_merchant_id' => array(
					'title'       => __('Merchant ID', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'default'     => ''
				),
				'vb_merchant_terminal' => array(
					'title'       => __('Merchant terminal', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'default'     => ''
				),
				'vb_merchant_name' => array(
					'title'       => __('Merchant name', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'default'     => ''
				),
				'vb_merchant_url' => array(
					'title'       => __('Merchant url', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => home_url(),
					'default'     => home_url() //https://codex.wordpress.org/Function_Reference/home_url
				),
				'vb_merchant_address' => array(
					'title'       => __('Merchant address', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => get_option('woocommerce_store_address'),
					'default'     => ''
				),

				'vb_public_key'   => array(
					'title'       => __('Public key file', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => 'pubkey.pem',
					'default'     => 'pubkey.pem'
				),
				'vb_private_key'  => array(
					'title'       => __('Private key file', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => 'key.pem',
					'default'     => 'key.pem'
				),
				'vb_private_key_pass' => array(
					'title'       => __('Private key passphrase', self::MOD_TEXT_DOMAIN),
					'type'        => 'password',
					'default'     => ''
				),
				'vb_bank_public_key' => array(
					'title'       => __('Bank public key file', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => 'victoria_pub.pem',
					'default'     => 'victoria_pub.pem'
				)
			);
		}

		protected function is_valid_for_use() {
			if(!in_array(get_option('woocommerce_currency'), array('MDL'))) {
				return false;
			}

			return true;
		}

		/**
		 * Admin Panel Options
		 * - Options for bits like 'title' and availability on a country-by-country basis
		 **/
		public function admin_options() {
			?>
			<h2><?php _e($this->method_title, self::MOD_TEXT_DOMAIN); ?></h2>
			<p><?php _e($this->method_description, self::MOD_TEXT_DOMAIN); ?></p>

			<?php if($this->is_valid_for_use()) : ?>
				<table class="form-table">
					<?php $this->generate_settings_html(); ?>
				</table>
			<?php else : ?>
				<div class="inline error">
					<p>
						<strong><?php _e('Payment gateway disabled', self::MOD_TEXT_DOMAIN); ?></strong>:
						<?php _e('Store settings not supported', self::MOD_TEXT_DOMAIN); ?>
					</p>
				</div>
			<?php
			endif;
		}

		protected function init_vb_client() {
			$victoriaBankGateway = new VictoriaBankGateway();

			//Set basic info
			$victoriaBankGateway
				->setMerchantId($this->vb_merchant_id)
				->setMerchantTerminal($this->vb_merchant_terminal)
				->setMerchantUrl($this->vb_merchant_url)
				->setMerchantName($this->vb_merchant_name)
				->setMerchantAddress($this->vb_merchant_address)
				->setTimezone(wc_timezone_string())
				//->setCountryCode('md') //WC_Countries::get_base_country();
				//->setDefaultCurrency('MDL') //get_woocommerce_currency();
				->setDefaultLanguage($this->get_language())
				->setDebug($this->debug);

			//Set security options - provided by the bank
			$victoriaBankGateway->setSecurityOptions(
				$this->vb_signature_first,
				$this->vb_signature_prefix,
				$this->vb_signature_padding,
				$this->vb_public_key,
				$this->vb_private_key,
				$this->vb_bank_public_key,
				$this->vb_private_key_pass);

			return $victoriaBankGateway;
		}

		public function process_payment($order_id) {
			if(!$order = wc_get_order($order_id)) {
				$message = sprintf(__('Order #%1$s not found', self::MOD_TEXT_DOMAIN), $order_id);
				$this->log($message, WC_Log_Levels::ERROR);

				wc_add_notice($message, 'error');

				return array(
					'result'    => 'failure',
					'messages'	=> $message
				);
			}

			if(is_ajax()) {
				return array(
					'result'   => 'success',
					'redirect' => $order->get_checkout_payment_url(true)
				);
			}

			try {
				$this->generate_form($order);
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);

				$message = sprintf(__('%1$s payment transaction failed', self::MOD_TEXT_DOMAIN), $this->method_title);
				wc_add_notice($message, 'error');
			}
		}

		public function order_status_completed($order_id) {
			$this->log(sprintf('order_status_completed: Order ID: %1$s', $order_id));

			if(!$this->transaction_auto)
				return;

			$order = wc_get_order($order_id);

			if($order && $order->get_payment_method() === $this->id) {
				if($order->has_status('completed') && $order->is_paid()) {
					$transaction_type = get_post_meta($order_id, strtolower(self::MOD_TRANSACTION_TYPE), true);

					if($transaction_type === self::TRANSACTION_TYPE_AUTHORIZATION) {
						return $this->complete_transaction($order_id, $order);
					}
				}
			}
		}

		public function order_status_cancelled($order_id) {
			$this->log(sprintf('order_status_cancelled: Order ID: %1$s', $order_id));

			if(!$this->transaction_auto)
				return;

			$order = wc_get_order($order_id);

			if($order && $order->get_payment_method() === $this->id) {
				if($order->has_status('cancelled') && $order->is_paid()) {
					$transaction_type = get_post_meta($order_id, strtolower(self::MOD_TRANSACTION_TYPE), true);

					if($transaction_type === self::TRANSACTION_TYPE_AUTHORIZATION) {
						return $this->refund_transaction($order_id, $order);
					}
				}
			}
		}

		public function complete_transaction($order_id, $order) {
			$this->log(sprintf('complete_transaction Order ID: %1$s', $order_id));

			$rrn = get_post_meta($order_id, strtolower(self::VB_RRN), true);
			$intRef = get_post_meta($order_id, strtolower(self::VB_INT_REF), true);
			$order_total = $this->get_order_net_total($order);
			$order_currency = $order->get_currency();

			//Funds locked on bank side - transfer the product/service to the customer and request completion
			try {
				$victoriaBankGateway = $this->init_vb_client();
				$completion_result = $victoriaBankGateway->requestCompletion(
					$order_id,
					$order_total,
					$rrn,
					$intRef,
					$order_currency
				);

				$this->log(self::print_var($completion_result));
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
			}

			return $completion_result;
		}

		public function refund_transaction($order_id, $order, $amount = null) {
			$this->log(sprintf('refund_transaction Order ID: %1$s Amount: %2$s', $order_id, $amount));

			if(!isset($amount)) {
				//Refund entirely if no amount is specified ???
				$amount = $order->get_total();
			}

			$rrn = get_post_meta($order_id, strtolower(self::VB_RRN), true);
			$intRef = get_post_meta($order_id, strtolower(self::VB_INT_REF), true);
			$order_currency = $order->get_currency();

			try {
				$victoriaBankGateway = $this->init_vb_client();
				$reversal_result = $victoriaBankGateway->requestReversal($order_id, $amount, $rrn, $intRef, $order_currency);

				$this->log(self::print_var($reversal_result));
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
			}

			return $reversal_result;
		}

		protected function check_transaction(WC_Order $order, $bankResponse) {
			//Validate order value
			$amount = $bankResponse->{AuthorizationResponse::AMOUNT};
			$currency = $bankResponse->{AuthorizationResponse::CURRENCY};

			$order_total = $order->get_total();
			$order_currency = $order->get_currency();

			return ($amount == $order_total) && ($currency === $order_currency);
		}

		public function check_redirect() {
			$this->log($_SERVER['REQUEST_METHOD'] . ' ' . $this->get_client_ip() . ' check_redirect ' . self::print_var($_REQUEST));

			$order_id = $_GET[self::VB_ORDER_ID];
			if(empty($order_id)) {
				$message = sprintf(__('Order ID not received from %1$s', self::MOD_TEXT_DOMAIN), $this->method_title);
				$this->log($message, WC_Log_Levels::ERROR);

				wc_add_notice($message, 'error');

				wp_redirect(wc_get_cart_url());
				return false;
			}

			$order = wc_get_order($order_id);
			if(!$order) {
				$message = sprintf(__('Order #%1$s not found as received from %2$s', self::MOD_TEXT_DOMAIN), $order_id, $this->method_title);
				$this->log($message, WC_Log_Levels::ERROR);

				wc_add_notice($message, 'error');

				wp_redirect(wc_get_cart_url());
				return false;
			}

			if($order->is_paid()) {
				WC()->cart->empty_cart();

				$message = sprintf(__('Order #%1$s paid successfully via %2$s', self::MOD_TEXT_DOMAIN), $order_id, $this->method_title);
				wc_add_notice($message, 'success');

				wp_redirect($this->get_return_url($order));
				return true;
			}
			else {
				$message = sprintf(__('Order #%1$s payment failed via %2$s', self::MOD_TEXT_DOMAIN), $order_id, $this->method_title);
				wc_add_notice($message, 'error');

				wp_redirect($order->get_checkout_payment_url());
				return false;
			}
		}

		public function check_response() {
			$this->log($_SERVER['REQUEST_METHOD'] . ' ' . $this->get_client_ip() . ' check_response ' . self::print_var($_REQUEST));

			$order_id = VictoriaBankGateway::deNormalizeOrderId($_POST[self::VB_ORDER]); //$bankResponse->{Response::ORDER}

			if(empty($order_id)) {
				$message = sprintf(__('Order ID not received from %1$s', self::MOD_TEXT_DOMAIN), $this->method_title);
				$this->log($message, WC_Log_Levels::ERROR);
				return false;
			}

			$order = wc_get_order($order_id);
			if(!$order) {
				$message = sprintf(__('Order #%1$s not found as received from %2$s', self::MOD_TEXT_DOMAIN), $order_id, $this->method_title);
				$this->log($message, WC_Log_Levels::ERROR);
				return false;
			}

			try {
				$victoriaBankGateway = $this->init_vb_client();
				$bankResponse = $victoriaBankGateway->getResponseObject($_POST);
				$check_result = $bankResponse->isValid();
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
			}

			if($check_result) {
				//region Extract bank response params
				$amount    = $bankResponse->{Response::AMOUNT};
				$currency  = $bankResponse->{Response::CURRENCY};
				$approval  = $bankResponse->{Response::APPROVAL};
				$rrn       = $bankResponse->{Response::RRN};
				$intRef    = $bankResponse->{Response::INT_REF};
				$timeStamp = $bankResponse->{Response::TIMESTAMP};

				$text      = $_POST['TEXT'];

				$bankParams = array(
					'AMOUNT' => $amount,
					'CURRENCY' => $currency,
					'TEXT' => $text,
					'APPROVAL' => $approval,
					'RRN' => $rrn,
					'INT_REF' => $intRef,
					'TIMESTAMP' => $timeStamp
				);
				//endregion

				switch($bankResponse::TRX_TYPE) {
					case VictoriaBankGateway::TRX_TYPE_AUTHORIZATION:
						$bin      = $_POST['BIN'];
						$card     = $_POST['CARD'];

						$bankParams['BIN'] = $bin;
						$bankParams['CARD'] = $card;

						//region Update order payment metadata
						add_post_meta($order_id, strtolower(self::MOD_TRANSACTION_TYPE), $this->transaction_type);

						foreach($bankParams as $key => $value) {
							add_post_meta($order_id, strtolower(self::MOD_PREFIX . $key), $value);
						}
						//endregion

						$message = sprintf(__('Payment authorized via %1$s: %2$s', self::MOD_TEXT_DOMAIN), $this->method_title, http_build_query($bankParams));
						$this->log($message, WC_Log_Levels::INFO);
						$order->add_order_note($message);

						$this->mark_order_paid($order, $intRef);

						switch($this->transaction_type) {
							case self::TRANSACTION_TYPE_CHARGE:
								$this->complete_transaction($order_id, $order);
								break;

							case self::TRANSACTION_TYPE_AUTHORIZATION:
								break;

							default:
								$this->log(sprintf(__('Unknown order #%1$s transaction type: %2$s', self::MOD_TEXT_DOMAIN), $order_id, $this->transaction_type), WC_Log_Levels::ERROR);
								break;
						}
						break;

					case VictoriaBankGateway::TRX_TYPE_COMPLETION:
						//Funds successfully transferred on bank side
						$message = sprintf(__('Payment completed via %1$s: %2$s', self::MOD_TEXT_DOMAIN), $this->method_title, http_build_query($bankParams));
						$this->log($message, WC_Log_Levels::INFO);
						$order->add_order_note($message);
						$this->mark_order_paid($order, $intRef);
						break;

					case VictoriaBankGateway::TRX_TYPE_REVERSAL:
						//Reversal successfully applied on bank side
						$message = sprintf(__('Refund of %1$s %2$s via %3$s approved: %4$s', self::MOD_TEXT_DOMAIN), $amount, $currency, $this->method_title, http_build_query($bankParams));
						$this->log($message, WC_Log_Levels::INFO);
						$order->add_order_note($message);

						if($order->get_total() == $order->get_total_refunded()) {
							$this->mark_order_refunded($order);
						}
						break;

					default:
						$this->log(sprintf(__('Unknown bank response TRX_TYPE: %1$s Order ID: %2$s', self::MOD_TEXT_DOMAIN), $bankResponse::TRX_TYPE, $order_id), WC_Log_Levels::ERROR);
						break;
				}
			}
			else {
				$this->log(sprintf(__('Payment transaction check failed for order #%1$s', self::MOD_TEXT_DOMAIN), $order_id), WC_Log_Levels::ERROR);
				$this->log(self::print_var($bankResponse), WC_Log_Levels::ERROR);

				$message = sprintf(__('%1$s payment transaction check failed: %2$s', self::MOD_TEXT_DOMAIN), $this->method_title, join('; ', $bankResponse->getErrors()));
				$order->add_order_note($message);
				return false;
			}
		}

		protected function mark_order_paid($order, $intRef) {
			if(!$order->is_paid()) {
				$order->payment_complete($intRef);
			}
		}

		protected function mark_order_refunded($order) {
			$order_note = sprintf(__('Order fully refunded via %1$s', self::MOD_TEXT_DOMAIN), $this->method_title);

			//Mark order as refunded if not already set
			if(!$order->has_status('refunded')) {
				$order->update_status('refunded', $order_note);
			} else {
				$order->add_order_note($order_note);
			}
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
			if(!$order = wc_get_order($order_id)) {
				$message = sprintf(__('Order #%1$s not found', self::MOD_TEXT_DOMAIN), $order_id);
				$this->log($message, WC_Log_Levels::ERROR);

				wc_add_notice($message, 'error');

				return array(
					'result'   => 'failure',
					'messages' => $message
				);
			}

			try {
				$this->generate_form($order);
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);

				$message = sprintf(__('%1$s payment transaction failed', self::MOD_TEXT_DOMAIN), $this->method_title);
				wc_add_notice($message, 'error');
			}
		}

		public function process_refund($order_id, $amount = NULL, $reason = '') {
			$order = wc_get_order($order_id);
			$refund_result = $this->refund_transaction($order_id, $order, $amount);

			return !empty($refund_result);
		}

		protected function get_order_net_total($order) {
			$order_total = $order->get_total();
			$total_refunded = $order->get_total_refunded();

			//https://github.com/woocommerce/woocommerce/issues/17795
			//https://github.com/woocommerce/woocommerce/pull/18196
			/*$total_refunded = 0;
			$order_refunds = $order->get_refunds();
			foreach($order_refunds as $refund) {
				if($refund->get_refunded_payment())
					$total_refunded += $refund->get_amount();
			}*/

			return $order_total - $total_refunded;
		}

		/**
		 * Format prices
		 *
		 * @param  float|int $price
		 *
		 * @return float|int
		 */
		protected function price_format($price) {
			$decimals = 2;

			return number_format($price, $decimals, '.', '');
		}

		protected function get_order_description($order) {
			//get_bloginfo('name')

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

		private $language_codes = array(
			'en_EN' => 'en',
			'ru_RU' => 'ru',
			'ro_RO' => 'ro'
		);

		protected function get_language() {
			$lang = get_locale();
			return substr($lang, 0, 2);
		}

		protected function get_client_ip() {
			//return $_SERVER['REMOTE_ADDR'];

			return WC_Geolocation::get_ip_address();
		}

		protected function get_callback_url() {
			//https://codex.wordpress.org/Function_Reference/site_url
			return add_query_arg('wc-api', get_class($this), home_url('/', 'https'));
		}

		protected function get_redirect_url() {
			return add_query_arg('wc-api', get_class($this) . '_redirect', home_url('/', 'https'));
		}

		//https://woocommerce.wordpress.com/2017/01/26/improved-logging-in-woocommerce-2-7/
		//https://stackoverflow.com/questions/1423157/print-php-call-stack
		protected function log($message, $level = WC_Log_Levels::DEBUG) {
			$this->logger->log($level, $message, $this->log_context);
		}

		static function print_var($var) {
			//https://docs.woocommerce.com/wc-apidocs/function-wc_print_r.html
			return print_r($var, true);
		}

		//region Admin
		static function plugin_links($links) {
			$settings_url = add_query_arg(
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => self::MOD_ID
				),
				admin_url('admin.php')
			);

			$plugin_links = array(
				sprintf('<a href="%1$s">%2$s</a>', esc_url($settings_url), __('Settings', self::MOD_TEXT_DOMAIN))
			);

			return array_merge($plugin_links, $links);
		}

		static function order_actions($actions) {
			global $theorder;
			if(!$theorder->is_paid() || $theorder->get_payment_method() !== self::MOD_ID) {
				return $actions;
			}

			$actions['victoriabank_complete_transaction'] = sprintf(__('Complete %1$s transaction', self::MOD_TEXT_DOMAIN), self::MOD_TITLE);
			$actions['victoriabank_reverse_transaction'] = sprintf(__('Reverse %1$s transaction', self::MOD_TEXT_DOMAIN), self::MOD_TITLE);
			return $actions;
		}

		static function action_complete_transaction($order) {
			$order_id = $order->get_id();

			$plugin = new self();
			return $plugin->complete_transaction($order_id, $order);
		}

		static function action_reverse_transaction($order) {
			$order_id = $order->get_id();

			$plugin = new self();
			return $plugin->refund_transaction($order_id, $order);
		}
		//endregion

		static function email_order_meta_fields($fields, $sent_to_admin, $order) {
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

		static function add_gateway($methods) {
			$methods[] = self::class;
			return $methods;
		}
	}

	//Add gateway to WooCommerce
	add_filter('woocommerce_payment_gateways', array(WC_VictoriaBank::class, 'add_gateway'));

	//region Admin init
	if(is_admin()) {
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(WC_VictoriaBank::class, 'plugin_links'));

		//Add WooCommerce order actions
		add_filter('woocommerce_order_actions', array(WC_VictoriaBank::class, 'order_actions'));
		add_action('woocommerce_order_action_victoriabank_complete_transaction', array(WC_VictoriaBank::class, 'action_complete_transaction'));
		add_action('woocommerce_order_action_victoriabank_reverse_transaction', array(WC_VictoriaBank::class, 'action_reverse_transaction'));
	}
	//endregion

	//Add WooCommerce email templates actions
	add_filter('woocommerce_email_order_meta_fields', array(WC_VictoriaBank::class, 'email_order_meta_fields', 10, 3));
}