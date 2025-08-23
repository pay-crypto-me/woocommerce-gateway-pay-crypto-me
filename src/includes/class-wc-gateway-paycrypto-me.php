<?php
/**
 * WC_Gateway_PayCrypto_Me class
 *
 * @author   Lucas Rosa <lucas.rosa95br@gmail.com>
 * @package  PayCrypto.Me Payments Gateway
 * @since    1.0.0
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * PayCrypto.Me Gateway.
 *
 * @class    WC_Gateway_PayCrypto_Me
 * @version  0.1.0
 */
class WC_Gateway_PayCrypto_Me extends WC_Payment_Gateway
{
	/**
	 * Payment gateway instructions.
	 * @var string
	 *
	 */
	protected $instructions;

	/**
	 * Whether the gateway is visible for non-admin users.
	 * @var boolean
	 *
	 */
	protected $hide_for_non_admin_users;

	/**
	 * Unique id for the gateway.
	 * @var string
	 *
	 */
	public $id = 'paycrypto-me';

	/**
	 * Constructor for the gateway.
	 */
	public function __construct()
	{
		$this->icon = apply_filters('woocommerce_dummy_gateway_icon', '');
		$this->has_fields = false;
		$this->supports = array(
			'pre-orders',
			'products',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'multiple_subscriptions'
		);

		$this->method_title = _x('Dummy Payment', 'Dummy payment method', 'woocommerce-gateway-dummy');
		$this->method_description = __('Allows dummy payments.', 'woocommerce-gateway-dummy');
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields()
	{
		//TODO: Implement the form fields for the gateway settings.
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param  int  $order_id
	 * @return array
	 */
	public function process_payment($order_id)
	{
		//TODO: Implement the payment processing logic.
	}

	/**
	 * Process pre-order payment upon order release.
	 *
	 * Processes the payment for pre-orders charged upon release.
	 *
	 * @param WC_Order $order The order object.
	 */
	public function process_pre_order_release_payment($order)
	{
		//TODO: Implement the pre-order release payment processing logic.
	}
}