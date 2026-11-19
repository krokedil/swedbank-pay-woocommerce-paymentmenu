<?php
namespace Krokedil\Swedbank\Pay\Utility;

defined( 'ABSPATH' ) || exit;

/**
 * Utility class for the payment method label in the checkout: the "What is Swedbank Pay?" link and the icon.
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
	 * Get the "What is Swedbank Pay?" link text.
	 *
	 * @return string
	 */
	public static function get_link_text() {
		return __( 'What is Swedbank Pay?', 'swedbank-pay-payment-menu' );
	}

	/**
	 * Get the "What is Swedbank Pay?" link URL.
	 *
	 * @return string
	 */
	public static function get_link_url() {
		// translators: URL of the "What is Swedbank Pay?" page. Change it to a page in your language if there is one.
		$url = __( 'https://www.swedbankpay.com/', 'swedbank-pay-payment-menu' );

		/**
		 * Filters the URL of the "What is Swedbank Pay?" link in the checkout.
		 *
		 * @param string $url The link URL.
		 */
		return apply_filters( 'swedbank_pay_what_is_link_url', $url );
	}

	/**
	 * Get the link and icon HTML that follows the payment method title in the classic checkout.
	 *
	 * @param string $key The instrument key, e.g. 'credit_card', or 'payex_checkout' for the payment menu.
	 * @param string $alt The alt text for the icon.
	 *
	 * @return string
	 */
	public static function get_label_html( $key, $alt ) {
		return sprintf(
			' <a class="swedbank-pay-what-is" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a> <img class="swedbank-pay-method-icon" src="%3$s" alt="%4$s" width="38" height="24" />',
			esc_url( self::get_link_url() ),
			esc_html( self::get_link_text() ),
			esc_url( self::get_icon_url( $key ) ),
			esc_attr( $alt )
		);
	}
}
