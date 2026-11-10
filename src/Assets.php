<?php
namespace Krokedil\Swedbank\Pay;

defined( 'ABSPATH' ) || exit;

/**
 * Class Assets
 *
 * Handles the registration and enqueuing of assets.
 */
class Assets {
	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ) );
	}

	/**
	 * Enqueue the styles for the payment method logos, on the pages that list payment methods.
	 *
	 * Only loaded when separate payment methods are enabled, since only those show a logo.
	 *
	 * @return void
	 */
	public function enqueue_checkout_assets() {
		if ( ! ( is_checkout() || is_add_payment_method_page() ) || ! Utility\SettingsUtility::is_separate_instruments_enabled() ) {
			return;
		}

		wp_enqueue_style(
			'swedbank-pay-checkout-icons',
			SWEDBANK_PAY_PLUGIN_URL . '/assets/css/checkout-icons.css',
			array(),
			SWEDBANK_PAY_VERSION
		);
	}

	/**
	 * Register admin assets.
	 *
	 * @return void
	 */
	public function register_assets() {
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		// Register the admin settings script.
		wp_register_script(
			'swedbank-pay-admin-settings',
			SWEDBANK_PAY_PLUGIN_URL . "/assets/js/admin-settings{$suffix}.js",
			array( 'jquery' ),
			SWEDBANK_PAY_VERSION,
			true
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		// If we are on the settings page for the Swedbank Pay plugin, enqueue the admin settings script.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- No form submission, just enqueueing script.
		if ( 'woocommerce_page_wc-settings' === $hook
			&& isset( $_GET['tab'] )
			&& 'checkout' === $_GET['tab']
			&& isset( $_GET['section'] )
			&& 'payex_checkout' === $_GET['section']
		) {
			// phpcs:enable
			$settings_params = array();
			wp_localize_script(
				'swedbank-pay-admin-settings',
				'swedbank_pay_admin_settings_params',
				$settings_params,
			);
			wp_enqueue_script( 'swedbank-pay-admin-settings' );
		}
	}
}
