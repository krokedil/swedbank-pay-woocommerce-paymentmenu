<?php
namespace Krokedil\Swedbank\Pay\CheckoutFlow;

use Krokedil\Swedbank\Pay\Utility\BlocksUtility;
use Krokedil\Swedbank\Pay\Utility\SettingsUtility;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Api;

/**
 * Abstract class for processing different checkout flows.
 */
abstract class CheckoutFlow {
	/**
	 * The payment gateway instance.
	 *
	 * @var \WC_Payment_Gateway
	 */
	protected $gateway;

	/**
	 * The API class instance.
	 *
	 * @var Swedbank_Pay_Api
	 */
	protected $api;

	/**
	 * Class constructor.
	 *
	 * @param \WC_Order $order The WooCommerce order to be processed.
	 *
	 * @return void
	 */
	public function __construct( $order ) {
		$this->gateway = swedbank_pay_get_payment_method( $order );
		$this->api     = $this->get_api();
	}

	/**
	 * Process the payment depending on the flow that should be used.
	 *
	 * @param int $order_id The WooCommerce order id.
	 *
	 * @throws \Exception If there is an error during the payment processing.
	 * @return array{redirect: array|bool|string, result: string}
	 */
	public static function process_payment( $order_id ) {
		try {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				throw new \Exception( __( 'Invalid order ID.', 'swedbank-pay-woocommerce-checkout' ) );
			}
			$handler = self::get_handler( $order );

			Swedbank_Pay()->logger()->info(
				sprintf(
					'Processing payment for order %s using %s flow.',
					$handler->get_order_number( $order ),
					get_class( $handler )
				),
				array(
					'handler'  => get_class( $handler ),
					'order_id' => $order->get_id(),
				)
			);
			return $handler->process( $order );
		} catch ( \Exception $e ) {
			return self::error_response( $e->getMessage() );
		}
	}

	/**
	 * Get the appropriate checkout flow handler based on the settings or context.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @return CheckoutFlow
	 */
	public static function get_handler( $order ) {
		$flow_setting   = SettingsUtility::get_setting( 'checkout_flow', 'redirect' );
		$blocks_enabled = BlocksUtility::is_checkout_block_enabled();
		$order_pay      = is_wc_endpoint_url( 'order-pay' );

		// If we are on the change_payment or order_pay pages, use the redirect flow no matter the setting.
		if ( SettingsUtility::is_redirect_flow() || $order_pay ) {
			return new Redirect( $order );
		}

		// If the checkout block is enabled, use the embedded block flow no matter the setting.
		if ( $blocks_enabled ) {
			return new InlineEmbeddedBlocks( $order );
		}

		switch ( $flow_setting ) {
			case 'embedded_inline':
				return new InlineEmbedded( $order );
			case 'redirect':
			default:
				return new Redirect( $order );
		}
	}

	/**
	 * Process the payment for the WooCommerce order.
	 *
	 * @param \WC_Order $order The WooCommerce order to be processed.
	 *
	 * @throws \Exception If there is an error during the payment processing.
	 * @return array{redirect: array|bool|string, result: string}
	 */
	abstract public function process( $order );

	/**
	 * Get the order number from the order, with a fallback to N/A.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 *
	 * @return string
	 */
	public function get_order_number( $order ) {
		return $order->get_order_number() ?? $order->get_id() ?? 'N/A';
	}

	/**
	 * Return error response.
	 *
	 * @param string|null $error_message The error message to return. If null, a default message will be used.
	 *
	 * @return array
	 */
	public static function error_response( $error_message = null ) {
		return array(
			'result'   => 'error',
			'messages' => $error_message ?? __( 'There was an error processing your payment. Please try again.', 'swedbank-pay-woocommerce-checkout' ),
		);
	}

	/**
	 * Get the API class instance.
	 *
	 * @return Swedbank_Pay_Api
	 */
	protected function get_api() {
		$api = new Swedbank_Pay_Api( $this->gateway );
		$api->set_access_token( SettingsUtility::get_setting( 'access_token' ) )
			->set_payee_id( SettingsUtility::get_setting( 'payee_id' ) )
			->set_mode( SettingsUtility::is_testmode() ? Swedbank_Pay_Api::MODE_TEST : Swedbank_Pay_Api::MODE_LIVE );

		return $api;
	}
}
