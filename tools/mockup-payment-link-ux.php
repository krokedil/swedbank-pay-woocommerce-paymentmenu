<?php
/**
 * Plugin Name: [DEV] Swedbank Pay payment link UX mockup
 * Description: Playground-only mockup of the proposed order-screen UX: external-link icon on the transaction link, and a Krokedil-styled "Swedbank Pay" metabox with a linked payment number and field tooltips. Also seeds a mock paid order so no real purchase is needed. Never shipped with the plugin.
 *
 * @package Swedbank_Pay_Payment_Menu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Fake but valid-looking paymentorder UUID used for the merchant portal link.
const SWEDBANK_PAY_MOCKUP_UUID           = 'e8e26e2b-0f5b-4a79-8ffa-e0e5e9e9a1b0';
const SWEDBANK_PAY_MOCKUP_TRANSACTION_ID = '40500159937';

/**
 * Create the mock paid order once (idempotent via the marker meta).
 */
function swedbank_pay_mockup_seed_order() {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return;
	}

	$existing = wc_get_orders(
		array(
			'limit'      => 1,
			'meta_key'   => '_mockup_payment_link_ux', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	if ( ! empty( $existing ) ) {
		return;
	}

	$order = wc_create_order();
	if ( is_wp_error( $order ) ) {
		return;
	}

	$order->add_product( swedbank_pay_mockup_get_product(), 1 );

	$address = array(
		'first_name' => 'Osama',
		'last_name'  => 'Testsson',
		'address_1'  => 'Testgatan 1',
		'postcode'   => '123 45',
		'city'       => 'Test',
		'country'    => 'SE',
	);
	$order->set_address(
		array_merge(
			$address,
			array(
				'email' => 'test@testsson.se',
				'phone' => '+46701234567',
			)
		),
		'billing'
	);
	$order->set_address( $address, 'shipping' );
	$order->set_customer_ip_address( '195.67.25.204' );

	$order->set_payment_method( 'payex_checkout' );
	$order->set_payment_method_title( 'Swedbank Pay Payment Menu' );

	// The real get_transaction_url() reads this meta to build the merchant portal link.
	$order->update_meta_data( '_payex_paymentorder_id', '/psp/paymentorders/' . SWEDBANK_PAY_MOCKUP_UUID );
	$order->update_meta_data( '_mockup_payment_link_ux', 'yes' );

	$order->calculate_totals();
	$order->payment_complete( SWEDBANK_PAY_MOCKUP_TRANSACTION_ID );
	$order->save();

	update_option( 'swedbank_pay_mockup_order_id', $order->get_id() );
}
add_action( 'admin_init', 'swedbank_pay_mockup_seed_order' );

/**
 * Get (or create) the simple product used on the mock order.
 *
 * @return WC_Product
 */
function swedbank_pay_mockup_get_product() {
	$product_id = wc_get_product_id_by_sku( 'swedbank-pay-mockup-product' );
	if ( $product_id ) {
		return wc_get_product( $product_id );
	}

	$product = new WC_Product_Simple();
	$product->set_name( 'Test Product' );
	$product->set_sku( 'swedbank-pay-mockup-product' );
	$product->set_regular_price( '100' );
	$product->save();

	return $product;
}

/**
 * Point to the mock order from the orders list, so it is easy to find.
 */
function swedbank_pay_mockup_admin_notice() {
	$screen   = get_current_screen();
	$order_id = get_option( 'swedbank_pay_mockup_order_id' );
	if ( ! $order_id || ! $screen || ! in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
		return;
	}
	$edit_url = get_admin_url( null, 'admin.php?page=wc-orders&action=edit&id=' . absint( $order_id ) );
	printf(
		'<div class="notice notice-info"><p>Payment link UX mockup: <a href="%s">open the mock order (#%d)</a>.</p></div>',
		esc_url( $edit_url ),
		absint( $order_id )
	);
}
add_action( 'admin_notices', 'swedbank_pay_mockup_admin_notice' );

/**
 * Remove the duplicate Capture/Cancel buttons under the order items table
 * (templates/admin/action-buttons.php) — the metabox is the single home for
 * the manual payment actions in the proposal.
 */
function swedbank_pay_mockup_remove_item_buttons() {
	remove_action(
		'woocommerce_order_item_add_action_buttons',
		'SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Admin::add_action_buttons',
		10
	);
}
add_action( 'admin_init', 'swedbank_pay_mockup_remove_item_buttons' );

/**
 * Replace the plugin's metabox with the proposed Krokedil-styled one.
 *
 * Runs after Swedbank_Pay_Admin::add_meta_boxes (priority 10). Removing the
 * original also skips its live GET {paymentorder}/paid API call, which would
 * fail for a mocked order anyway.
 *
 * @param string $post_type Post type of the current screen.
 */
function swedbank_pay_mockup_replace_metabox( $post_type ) {
	$hpos_screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
	if ( ! in_array( $post_type, array( 'shop_order', $hpos_screen ), true ) ) {
		return;
	}

	remove_meta_box( 'swedbank_payment_actions', $post_type, 'side' );

	add_meta_box(
		'swedbank_pay_mockup',
		'Swedbank Pay',
		'swedbank_pay_mockup_render_metabox',
		$post_type,
		'side',
		'high'
	);

	// Pin the mockup metabox to the top of the sidebar, like in the proposal
	// screenshot (registration order decides within the same priority).
	global $wp_meta_boxes;
	if ( isset( $wp_meta_boxes[ $post_type ]['side']['high']['swedbank_pay_mockup'] ) ) {
		$box = $wp_meta_boxes[ $post_type ]['side']['high']['swedbank_pay_mockup'];
		unset( $wp_meta_boxes[ $post_type ]['side']['high']['swedbank_pay_mockup'] );
		$wp_meta_boxes[ $post_type ]['side']['high'] = array_merge(
			array( 'swedbank_pay_mockup' => $box ),
			$wp_meta_boxes[ $post_type ]['side']['high']
		);
	}
}
add_action( 'add_meta_boxes', 'swedbank_pay_mockup_replace_metabox', 99 );

/**
 * Render the proposed metabox design (mock data, inert buttons).
 *
 * @param WP_Post|WC_Order $post_or_order The post or order object for the screen.
 */
function swedbank_pay_mockup_render_metabox( $post_or_order ) {
	$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
	if ( ! $order || ! $order->get_meta( '_payex_paymentorder_id' ) ) {
		echo '<p>Not a Swedbank Pay order.</p>';
		return;
	}

	$portal_url = 'https://merchantportal.swedbankpay.com/psp/transactions/paymentorder/' . rawurlencode( SWEDBANK_PAY_MOCKUP_UUID );
	$payee_ref  = $order->get_id() . 'xcxiql';

	$rows = array(
		array(
			'label' => 'Number',
			'value' => sprintf(
				'<a href="%s" target="_blank">%s</a>',
				esc_url( $portal_url ),
				esc_html( $order->get_transaction_id() )
			),
			'tip'   => 'The payment number in Swedbank Pay. Click it to view the payment in the Swedbank Pay Merchant Portal.',
		),
		array(
			'label' => 'Instrument',
			'value' => 'CreditCard',
			'tip'   => 'The payment method the customer paid with.',
		),
		array(
			'label' => 'Transaction type',
			'value' => 'Authorization',
			'tip'   => 'The current transaction state of the payment.',
		),
		array(
			'label' => 'Payee reference',
			'value' => esc_html( $payee_ref ),
			'tip'   => 'The unique reference sent to Swedbank Pay for this order.',
		),
	);

	echo '<div class="krokedil_wc__metabox">';
	foreach ( $rows as $row ) {
		echo '<h4>' . esc_html( $row['label'] );
		if ( ! empty( $row['tip'] ) ) {
			echo wp_kses_post( wc_help_tip( $row['tip'] ) );
		}
		echo '</h4>';
		echo '<span>' . wp_kses_post( $row['value'] ) . '</span>';
	}

	// Advanced section, collapsed by default (same pattern as the Qliro plugin).
	// Capture/cancel normally happen via WooCommerce order status changes, so the
	// manual buttons are tucked away and secondary. Inert in the mockup.
	echo '<div class="krokedil_wc__metabox_section">';
	echo '<h4 class="krokedil_wc__metabox_label krokedil_wc__metabox_section_toggle">Advanced <span class="dashicons dashicons-arrow-down"></span></h4>';
	echo '<div id="swedbank-pay-advanced" class="krokedil_wc__metabox_section_content" style="display:none;">';
	echo '<div class="krokedil_wc__metabox_button_row">';
	echo '<a href="#" class="krokedil_wc__metabox_button krokedil_wc__metabox_action button">Capture payment</a>';
	echo '<a href="#" class="krokedil_wc__metabox_button krokedil_wc__metabox_action button">Cancel payment</a>';
	echo '</div>';
	echo '</div>';
	echo '</div>';
	echo '</div>';
	?>
	<script>
		// krokedil/woocommerce assets/js/metabox.js — collapse toggle.
		jQuery( function( $ ) {
			$( '.krokedil_wc__metabox_section_toggle' ).on( 'click', function() {
				$( this )
					.siblings( '.krokedil_wc__metabox_section_content' )
					.slideToggle( { duration: 150, easing: 'linear' } );
				$( this ).find( '.dashicons' ).toggleClass( 'krokedil_wc__metabox_open' );
			} );
		} );
	</script>
	<?php
}

/**
 * Styles: the krokedil/woocommerce metabox.css, plus the external-link icon
 * (same base64 PNG krokedil/settings-page appends to target="_blank" links)
 * applied to the order-header transaction link and links in the metabox.
 */
function swedbank_pay_mockup_admin_styles() {
	$screen      = get_current_screen();
	$hpos_screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
	if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', $hpos_screen ), true ) ) {
		return;
	}
	?>
	<style id="swedbank-pay-mockup-css">
		/* krokedil/woocommerce assets/css/metabox.css */
		.krokedil_wc__metabox h4 {
			margin-bottom: .1em;
		}
		.krokedil_wc__metabox_section {
			margin-top: 2em;
			padding-top: 1em;
			border-top: 1px solid #e5e5e5;
		}
		h4.krokedil_wc__metabox_label {
			cursor: pointer;
			display: flex;
			gap: 1em;
			margin: 0;
		}
		.krokedil_wc__metabox_section_content {
			display: flex;
			flex-direction: column;
		}
		.krokedil_wc__metabox_label .dashicons {
			transition: transform 0.15s ease;
		}
		.krokedil_wc__metabox_label .dashicons.krokedil_wc__metabox_open {
			transform: rotate(-180deg);
		}
		div.krokedil_wc__metabox div.krokedil_wc__metabox_button,
		div.krokedil_wc__metabox a.krokedil_wc__metabox_action {
			margin-top: 1em;
		}
		.krokedil_wc__metabox_toggle_wrapper {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-top: 1em;
		}
		.krokedil_wc__metabox_toggle_wrapper h4 {
			margin-top: 0;
		}
		.krokedil_wc__metabox_toggle_wrapper .woocommerce-input-toggle {
			cursor: pointer;
		}

		/* Mockup: the two action buttons share one row, equal widths. */
		.krokedil_wc__metabox_button_row {
			display: flex;
			gap: .5em;
		}
		.krokedil_wc__metabox_button_row .krokedil_wc__metabox_action {
			flex: 1;
			text-align: center;
			/* Slightly condensed so both full labels fit side by side in the sidebar. */
			font-size: 12px;
			padding: 0 6px;
		}

		/* External-link icon (same image as krokedil/settings-page uses):
		   order-header transaction link + external links in the metabox. */
		#order_data .woocommerce-order-data__meta a[target="_blank"]::after,
		.krokedil_wc__metabox a[target="_blank"]::after {
			content: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAQElEQVR42qXKwQkAIAxDUUdxtO6/RBQkQZvSi8I/pL4BoGw/XPkh4XigPmsUgh0626AjRsgxHTkUThsG2T/sIlzdTsp52kSS1wAAAABJRU5ErkJggg==);
			margin: 0 3px 0 5px;
		}
	</style>
	<?php
}
add_action( 'admin_head', 'swedbank_pay_mockup_admin_styles' );
