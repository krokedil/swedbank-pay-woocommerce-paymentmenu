<?php
namespace Krokedil\Swedbank\Pay\Utility;

defined( 'ABSPATH' ) || exit;

/**
 * Utility class for the payment method icon in the checkout.
 */
class CheckoutLabelUtility {
	/**
	 * Get the icon URL for a payment method, falling back to the Swedbank Pay logo.
	 *
	 * @param string $key The instrument key, e.g. 'credit_card', or 'payex_checkout' for the payment menu.
	 *
	 * @return string
	 */
	public static function get_icon_url( $key ) {
		$file = sanitize_key( $key ) . '.svg';
		if ( 'payex_checkout' === $key || ! file_exists( SWEDBANK_PAY_PLUGIN_PATH . "/assets/images/icons/$file" ) ) {
			$file = 'logo.svg';
		}

		/**
		 * Filters the icon URL shown next to a Swedbank Pay payment method in the checkout.
		 *
		 * @param string $url The icon URL.
		 * @param string $key The instrument key, e.g. 'credit_card', or 'payex_checkout' for the payment menu.
		 */
		return apply_filters( 'swedbank_pay_payment_method_icon_url', SWEDBANK_PAY_PLUGIN_URL . "/assets/images/icons/$file", $key );
	}

	/**
	 * Get the icon HTML that follows the payment method title in the classic checkout.
	 *
	 * @param string $key The instrument key, e.g. 'credit_card', or 'payex_checkout' for the payment menu.
	 *
	 * @return string
	 */
	public static function get_label_html( $key ) {
		return sprintf(
			' <img class="swedbank-pay-method-icon" src="%s" alt="" width="38" height="24" />',
			esc_url( self::get_icon_url( $key ) )
		);
	}
}
