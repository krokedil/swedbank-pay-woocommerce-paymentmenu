<?php

namespace SwedbankPay\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

use WC_Order;
use WC_Log_Levels;
use Exception;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Subscription;

/**
 * @SuppressWarnings(PHPMD.CamelCaseClassName)
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 * @SuppressWarnings(PHPMD.CamelCasePropertyName)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class Swedbank_Pay_Admin {
	/**
	 * Constructor
	 */
	public function __construct() {
		// Add statuses for payment complete.
		add_filter(
			'woocommerce_valid_order_statuses_for_payment_complete',
			array( $this, 'add_valid_order_statuses' ),
			10,
			2
		);

		// Add meta boxes.
		add_action( 'add_meta_boxes', __CLASS__ . '::add_meta_boxes', 10, 2 );

		// Remove "Order fully refunded" hook. See wc_order_fully_refunded().
		remove_action( 'woocommerce_order_status_refunded', 'wc_order_fully_refunded' );
		add_action( 'woocommerce_order_status_changed', __CLASS__ . '::order_status_changed_transaction', 0, 3 );

		// Refund actions.
		add_action( 'woocommerce_create_refund', array( $this, 'save_refund_parameters' ), 10, 2 );
		add_action( 'woocommerce_order_refunded', array( $this, 'remove_refund_parameters' ), 10, 2 );
		add_action( 'woocommerce_order_fully_refunded', array( $this, 'prevent_online_refund' ), 10, 2 );

		add_filter(
			'woocommerce_admin_order_should_render_refunds',
			array( $this, 'order_should_render_refunds' ),
			10,
			3
		);
	}

	public function prevent_online_refund( $order_id, $refund_id ) {
		$order          = wc_get_order( $order_id );
		$payment_method = $order->get_payment_method();
		if ( in_array( $payment_method, Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
			// Prevent online refund when order status changed to "refunded".
			set_transient(
				'sb_refund_prevent_online_refund_' . $order_id,
				$refund_id,
				5 * MINUTE_IN_SECONDS
			);
		}
	}

	/**
	 * Allow processing/completed statuses for capture
	 *
	 * @param array    $statuses
	 * @param WC_Order $order
	 *
	 * @return array
	 */
	public function add_valid_order_statuses( $statuses, $order ) {
		$payment_method = $order->get_payment_method();
		if ( in_array( $payment_method, Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
			$statuses = array_merge(
				$statuses,
				array(
					'processing',
					'completed',
				)
			);
		}

		return $statuses;
	}

	/**
	 * Add meta boxes in admin.
	 *
	 * @param $screen_id
	 * @param WC_Order|\WP_Post $order
	 * @return void
	 */
	public static function add_meta_boxes( $screen_id, $order ) {
		$payment_method = $order->get_payment_method();
		if ( in_array( $payment_method, Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
			$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
			if ( ! empty( $payment_order_id ) ) {
				add_meta_box(
					'swedbank_payment_actions',
					__( 'Swedbank Pay Payment Information', 'swedbank-pay-woocommerce-checkout' ),
					__CLASS__ . '::order_meta_box_content',
					$screen_id,
					'side',
					'high'
				);
			}
		}
	}

	/**
	 * Loads the content for the metabox.
	 *
	 * @param WC_Order|\WP_Post $order The WC order object or post object.
	 * @return void
	 */
	public static function order_meta_box_content( $order ) {
		$order            = wc_get_order( $order );
		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			return;
		}

		// Get Payment Gateway.
		/** @var \Swedbank_Pay_Payment_Gateway_Checkout $gateway */
		$gateway = swedbank_pay_get_payment_method( $order );
		if ( ! $gateway ) {
			return;
		}

		// Fetch payment info.
		$result = $gateway->api->request( 'GET', "$payment_order_id/paid" );
		if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
			return;
		}

		wc_get_template(
			'admin/metabox-content.php',
			array(
				'gateway' => $gateway,
				'order'   => $order,
				'info'    => $result,
			),
			'',
			SWEDBANK_PAY_PLUGIN_PATH . '/templates/'
		);
	}

	/**
	 * @param $order_id
	 * @param $old_status
	 * @param $new_status
	 *
	 * @return void
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public static function order_status_changed_transaction( $order_id, $old_status, $new_status ) {
		$order = wc_get_order( $order_id );
		if ( ! in_array( $order->get_payment_method(), Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
			return;
		}

		// Allow to change status from `processing` to `completed`.
		if ( 'processing' === $old_status && 'completed' === $new_status ) {
			return;
		}

		// Allow to change status from `pending` to `cancelled`.
		if ( 'pending' === $old_status && 'cancelled' === $new_status ) {
			return;
		}

		if ( Swedbank_Pay_Subscription::should_skip_order_management( $order ) ) {
			return;
		}

		$gateway = swedbank_pay_get_payment_method( $order );

		Swedbank_Pay()->logger()->log(
			'Order status change trigger: ' . $new_status . ' OrderID: ' . $order_id
		);

		try {
			switch ( $new_status ) {
				case 'completed':
					$payment_id = $order->get_meta( '_payex_paymentorder_id' );
					if ( $payment_id && $gateway->api->is_captured( $payment_id ) ) {
						$gateway->api->log( WC_Log_Levels::INFO, "The order {$order->get_order_number()} is already captured." );
						return;
					}
					$gateway->api->log( WC_Log_Levels::INFO, 'Try to capture...' );
					$result = $gateway->payment_actions_handler->capture_payment( $order );
					if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
						/** @var \WP_Error $result */
						throw new \Exception( $result->get_error_message() );
					}

					$order->add_order_note(
						__( 'Payment has been captured by order status change.', 'swedbank-pay-woocommerce-checkout' )
					);

					break;
				case 'cancelled':
					$gateway->api->log( WC_Log_Levels::INFO, 'Try to cancel...' );
					$result = $gateway->payment_actions_handler->cancel_payment( $order );
					if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
						/** @var \WP_Error $result */
						throw new \Exception( $result->get_error_message() );
					}

					$order->add_order_note(
						__( 'Payment has been cancelled by order status change.', 'swedbank-pay-woocommerce-checkout' )
					);

					break;
				case 'refunded':
					$refund_id = get_transient( 'sb_refund_prevent_online_refund_' . $order_id );
					if ( ! empty( $refund_id ) ) {
						delete_transient( 'sb_refund_prevent_online_refund_' . $order_id );

						return;
					}

					$gateway->api->log( WC_Log_Levels::INFO, 'Try to refund...' );
					$lines  = swedbank_pay_get_available_line_items_for_refund( $order );
					$result = $gateway->payment_actions_handler->refund_payment(
						$order,
						$lines,
						__( 'Order status changed to refunded.', 'swedbank-pay-woocommerce-checkout' ),
						true
					);
					if ( is_wp_error( Swedbank_Pay()->system_report()->request( $result ) ) ) {
						/** @var \WP_Error $result */
						throw new \Exception( $result->get_error_message() );
					}

					$order->add_order_note(
						__( 'Payment has been refunded by order status change.', 'swedbank-pay-woocommerce-checkout' )
					);

					break;
			}
		} catch ( \Exception $exception ) {
			\WC_Admin_Meta_Boxes::add_error( 'Order status change action error: ' . $exception->getMessage() );

			// Rollback status.
			remove_action(
				'woocommerce_order_status_changed',
				__CLASS__ . '::order_status_changed_transaction',
				0
			);

			$order->add_order_note(
				sprintf(
					'Order status change "%s->%s" action error: %s',
					$old_status,
					$new_status,
					$exception->getMessage()
				)
			);
		}
	}

	/**
	 * Save refund parameters to perform refund with specified products and amounts.
	 *
	 * @param \WC_Order_Refund $refund
	 * @param $args
	 */
	public function save_refund_parameters( $refund, $args ) {
		if ( ! isset( $args['order_id'] ) ) {
			return;
		}

		$order = wc_get_order( $args['order_id'] );
		if ( ! $order ) {
			return;
		}

		if ( ! in_array( $order->get_payment_method(), Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
			return;
		}

		// Save order items of refund.
		set_transient(
			'sb_refund_parameters_' . $args['order_id'],
			$args,
			5 * MINUTE_IN_SECONDS
		);

		// Preserve refund.
		$refund->save();
	}

	/**
	 * Remove refund parameters.
	 *
	 * @param $order_id
	 * @param $refund_id
	 *
	 * @return void
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function remove_refund_parameters( $order_id, $refund_id ) {
		delete_transient( 'sb_refund_parameters_' . $order_id );
	}

	/**
	 * Controls native Refund button.
	 *
	 * @param bool     $should_render
	 * @param mixed    $order_id
	 * @param WC_Order $order
	 *
	 * @return bool
	 */
	public function order_should_render_refunds( $should_render, $order_id, $order ) {
		if ( ! in_array( $order->get_payment_method(), Swedbank_Pay_Plugin::PAYMENT_METHODS, true ) ) {
			return $should_render;
		}

		$gateway = swedbank_pay_get_payment_method( $order );
		if ( ! $gateway ) {
			return $should_render;
		}

		$can_refund = $gateway->api->can_refund( $order );
		if ( ! $can_refund ) {
			return false;
		}

		return $should_render;
	}
}

new Swedbank_Pay_Admin();
