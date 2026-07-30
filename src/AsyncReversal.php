<?php
/**
 * Class AsyncReversal
 *
 * Handles asynchronous reversals (refunds) that are accepted with HTTP 202 and
 * confirmed later through a payee callback.
 */

namespace Krokedil\Swedbank\Pay;

use Krokedil\Swedbank\Pay\Utility\LogUtility;
use SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Order_Item;

defined( 'ABSPATH' ) || exit;

/**
 * Class AsyncReversal
 *
 * Some payment methods (e.g. Banklink in the Baltics) answer a reversal with HTTP 202 and report
 * the outcome later. Confirmed reversals appear in `financialTransactions`, rejected ones in
 * `postpurchaseFailedAttempts`.
 */
class AsyncReversal {
	use Traits\Singleton;

	public const PENDING_REVERSALS_META = '_swedbank_pay_pending_reversals';

	/**
	 * Handled failed attempt numbers, so an older failure is not attributed to a later reversal.
	 */
	public const SEEN_FAILED_ATTEMPTS_META = '_swedbank_pay_seen_failed_attempts';

	/**
	 * Clock skew allowance, in seconds, when matching a failed attempt to a pending entry.
	 */
	private const FAILED_ATTEMPT_TOLERANCE = 300;

	/**
	 * Action Scheduler hook for rechecking a reversal whose callback has not arrived.
	 */
	public const RECHECK_HOOK = 'swedbank_pay_recheck_pending_reversals';

	/**
	 * Class constructor.
	 */
	public function __construct() {
		add_action( self::RECHECK_HOOK, array( $this, 'handle_recheck' ), 10, 2 );
	}

	/**
	 * Delays between rechecks, in seconds, spanning the three day worst case.
	 *
	 * @return int[]
	 */
	private function get_recheck_delays() {
		/**
		 * Filter the delays between rechecks of a pending asynchronous reversal.
		 *
		 * @param int[] $delays Delays in seconds, one per recheck attempt.
		 */
		$delays = apply_filters(
			'swedbank_pay_pending_reversal_recheck_delays',
			array( 5 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS, 8 * HOUR_IN_SECONDS, DAY_IN_SECONDS, DAY_IN_SECONDS, DAY_IN_SECONDS )
		);

		return array_values( array_filter( array_map( 'intval', (array) $delays ) ) );
	}

	/**
	 * Schedule the next recheck of an order's pending reversals.
	 *
	 * @param \WC_Order $order The order.
	 * @param int       $attempt The recheck attempt to schedule, zero based.
	 *
	 * @return bool Whether a recheck was scheduled.
	 */
	private function schedule_recheck( \WC_Order $order, $attempt ) {
		$delays = $this->get_recheck_delays();
		if ( ! isset( $delays[ $attempt ] ) ) {
			return false;
		}

		$action_id = as_schedule_single_action(
			time() + $delays[ $attempt ],
			self::RECHECK_HOOK,
			array(
				'order_id' => $order->get_id(),
				'attempt'  => $attempt,
			)
		);

		// Action Scheduler returns 0 when it could not store the action.
		if ( 0 === $action_id ) {
			Swedbank_Pay()->logger()->error(
				"[ASYNC REVERSAL]: Failed to schedule recheck attempt {$attempt} for order #{$order->get_order_number()}.",
				array(
					'action'   => 'schedule_recheck',
					'order_id' => $order->get_id(),
					'attempt'  => $attempt,
				)
			);

			return false;
		}

		return true;
	}

	/**
	 * Recheck an order's pending reversals when the callback has not arrived.
	 *
	 * @param int $order_id The order to recheck.
	 * @param int $attempt The recheck attempt that is running, zero based.
	 *
	 * @return void
	 */
	public function handle_recheck( $order_id, $attempt = 0 ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! $this->has_pending( $order ) ) {
			return;
		}

		$context = array(
			'action'   => 'recheck_pending_reversals',
			'order_id' => $order->get_id(),
			'attempt'  => $attempt,
		);

		$result = $this->check_pending_reversals( $order );
		if ( is_wp_error( $result ) ) {
			$context['error'] = $result->get_error_message();
			Swedbank_Pay()->logger()->error( "[ASYNC REVERSAL]: Recheck failed for order #{$order->get_order_number()}: {$result->get_error_message()}", $context );
		}

		// Resolved, either just now or by a callback that arrived in the meantime.
		if ( ! $this->has_pending( $order ) ) {
			return;
		}

		// Retry until the documented three day window is exhausted, then hand it to the merchant.
		if ( ! $this->schedule_recheck( $order, (int) $attempt + 1 ) ) {
			$this->abandon_pending( $order );
		}
	}

	/**
	 * Give up on reversals that Swedbank Pay never reported an outcome for.
	 *
	 * Puts the order on hold so the merchant verifies it manually, and drops the pending
	 * entries so the order is not blocked from further payment actions indefinitely.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return void
	 */
	private function abandon_pending( \WC_Order $order ) {
		$order->read_meta_data();
		$pending = $this->get_pending( $order );

		$context = array(
			'action'           => 'abandon_pending_reversals',
			'order_id'         => $order->get_id(),
			'order_number'     => $order->get_order_number(),
			'payment_order_id' => $order->get_meta( '_payex_paymentorder_id' ),
			'pending'          => array_keys( $pending ),
		);

		Swedbank_Pay()->logger()->error( "[ASYNC REVERSAL]: Giving up on unconfirmed reversal(s) for order #{$order->get_order_number()}.", $context );

		$this->save_pending( $order, array() );

		$message = __( 'Swedbank Pay never confirmed whether the refund succeeded. Please verify the refund with Swedbank Pay before taking any further action on this order.', 'swedbank-pay-payment-menu' );

		$gateway = swedbank_pay_get_payment_method( $order );
		if ( $gateway && property_exists( $gateway, 'api' ) ) {
			$gateway->api->update_order_status( $order, 'on-hold', $order->get_transaction_id(), $message );
		} else {
			$order->update_status( 'on-hold', $message );
		}
	}

	/**
	 * Register a reversal that was accepted (HTTP 202) but not yet confirmed.
	 *
	 * Stores a pending entry on the order, keyed by the payeeReference sent in the
	 * reversal request, and returns a synthetic transaction array so callers treat
	 * the refund as successfully submitted.
	 *
	 * @param \WC_Order             $order The order being refunded.
	 * @param object                $transaction_data The TransactionData object sent in the reversal request.
	 * @param string                $mode 'amount' or 'items'.
	 * @param \WC_Order_Refund|null $refund_order The WC refund connected to the reversal, if known.
	 *
	 * @return array Synthetic transaction array with `state` = 'Pending' and `pending` = true.
	 */
	public function init_pending( \WC_Order $order, $transaction_data, $mode, $refund_order = null ) {
		$payee_reference = $transaction_data->getPayeeReference();
		$amount          = $transaction_data->getAmount();

		$refund_id = 0;
		if ( $refund_order instanceof \WC_Order_Refund ) {
			$refund_id = $refund_order->get_id();
		} else {
			// WooCommerce creates the refund before the gateway's process_refund() runs.
			$refunds = $order->get_refunds();
			$refund  = reset( $refunds );
			if ( $refund instanceof \WC_Order_Refund ) {
				$refund_id = $refund->get_id();
			}
		}

		$context = array(
			'action'           => 'init_pending_reversal',
			'order_id'         => $order->get_id(),
			'order_number'     => $order->get_order_number(),
			'payment_order_id' => $order->get_meta( '_payex_paymentorder_id' ),
			'payee_reference'  => $payee_reference,
			'amount'           => $amount,
			'refund_id'        => $refund_id,
			'mode'             => $mode,
		);

		// Reload order meta to ensure we have the latest changes and avoid conflicts from parallel scripts.
		$order->read_meta_data();
		$pending = $this->get_pending( $order );

		$pending[ $payee_reference ] = array(
			'amount'    => $amount,
			'created'   => gmdate( 'c' ),
			'refund_id' => $refund_id,
			'mode'      => $mode,
			'lines'     => null,
		);

		$order->update_meta_data( self::PENDING_REVERSALS_META, $pending );
		$order->save_meta_data();

		$order->add_order_note(
			sprintf(
				// translators: %s: refund amount.
				__( 'The refund of %s has been sent to Swedbank Pay and is awaiting confirmation.', 'swedbank-pay-payment-menu' ),
				wc_price( $amount / 100, array( 'currency' => $order->get_currency() ) )
			)
		);

		// Safety net if the callback never arrives; non-fatal, schedule_recheck() logs a failure.
		$this->schedule_recheck( $order, 0 );

		Swedbank_Pay()->logger()->info( "[ASYNC REVERSAL]: Reversal accepted asynchronously (HTTP 202) for order #{$order->get_order_number()}. Awaiting confirmation via callback.", $context );

		return array(
			'number'         => '',
			'type'           => OrderManagement::TYPE_REVERSAL,
			'state'          => 'Pending',
			'amount'         => $amount,
			'created'        => gmdate( 'c' ),
			'updated'        => gmdate( 'c' ),
			'description'    => sprintf( 'Refund Order #%s', $order->get_order_number() ),
			'payeeReference' => $payee_reference,
			'pending'        => true,
		);
	}

	/**
	 * Whether the order has reversals awaiting confirmation.
	 *
	 * @param \WC_Order $order The order to check.
	 *
	 * @return bool
	 */
	public function has_pending( \WC_Order $order ) {
		return ! empty( $this->get_pending( $order ) );
	}

	/**
	 * Update a single field on a pending reversal entry.
	 *
	 * @param \WC_Order $order The order.
	 * @param string    $payee_reference The pending entry key.
	 * @param string    $field The field to set.
	 * @param mixed     $value The value to set.
	 *
	 * @return void
	 */
	public function set_pending_field( \WC_Order $order, $payee_reference, $field, $value ) {
		$order->read_meta_data();
		$pending = $this->get_pending( $order );
		if ( ! isset( $pending[ $payee_reference ] ) ) {
			return;
		}

		$pending[ $payee_reference ][ $field ] = $value;
		$order->update_meta_data( self::PENDING_REVERSALS_META, $pending );
		$order->save_meta_data();
	}

	/**
	 * Resolve pending reversals against the payment order.
	 *
	 * Confirmed reversals are found in `financialTransactions` and processed through the
	 * regular transaction handling. Failed reversals are found in `postPurchaseFailedAttempts`
	 * and put the order on hold. Entries that appear in neither are left pending.
	 *
	 * @param \WC_Order $order The order to check.
	 *
	 * @return true|\WP_Error True when nothing is left to do (including nothing pending).
	 */
	public function check_pending_reversals( \WC_Order $order ) {
		$order->read_meta_data();
		$pending = $this->get_pending( $order );
		if ( empty( $pending ) ) {
			return true;
		}

		$payment_order_id = $order->get_meta( '_payex_paymentorder_id' );
		if ( empty( $payment_order_id ) ) {
			return new \WP_Error( 'missing_payment_id', 'Payment order ID is unknown.' );
		}

		$gateway = swedbank_pay_get_payment_method( $order );
		if ( ! $gateway || ! property_exists( $gateway, 'api' ) ) {
			return new \WP_Error( 'missing_gateway', 'Unable to retrieve the payment gateway instance.' );
		}
		$api = $gateway->api;

		$context = array(
			'action'           => 'check_pending_reversals',
			'order_id'         => $order->get_id(),
			'order_number'     => $order->get_order_number(),
			'payment_order_id' => $payment_order_id,
			'pending'          => array_keys( $pending ),
		);

		// Check confirmed reversals in the financial transactions list.
		LogUtility::$title = "[ASYNC REVERSAL]: Retrieve financial transactions for order #{$order->get_order_number()}";
		$result            = $api->request( 'GET', "{$payment_order_id}/financialtransactions" );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$transactions_list = $result['financialTransactions']['financialTransactionsList'] ?? array();
		foreach ( $transactions_list as $transaction ) {
			if ( OrderManagement::TYPE_REVERSAL !== ( $transaction['type'] ?? '' ) ) {
				continue;
			}

			$payee_reference = $transaction['payeeReference'] ?? '';
			if ( ! isset( $pending[ $payee_reference ] ) ) {
				continue;
			}

			$process_result = $api->process_transaction( $order, $transaction );
			if ( is_wp_error( $process_result ) ) {
				Swedbank_Pay()->logger()->error( "[ASYNC REVERSAL]: Failed to process confirmed reversal #{$transaction['number']} for order #{$order->get_order_number()}: {$process_result->get_error_message()}", $context );
				continue;
			}

			unset( $pending[ $payee_reference ] );
			$this->save_pending( $order, $pending );

			$order->add_order_note(
				sprintf(
					// translators: %s: refund amount.
					__( 'Swedbank Pay has confirmed the refund of %s. The money is on its way back to the customer.', 'swedbank-pay-payment-menu' ),
					wc_price( $transaction['amount'] / 100, array( 'currency' => $order->get_currency() ) )
				)
			);

			Swedbank_Pay()->logger()->info( "[ASYNC REVERSAL]: Reversal confirmed for order #{$order->get_order_number()}. Transaction: #{$transaction['number']}.", $context );
		}

		if ( empty( $pending ) ) {
			return true;
		}

		// Check failed reversals in the post purchase failed attempts list.
		$failed_attempts = $this->get_failed_attempts( $api, $order, $payment_order_id );
		if ( is_wp_error( $failed_attempts ) ) {
			return $failed_attempts;
		}

		$seen = $this->get_seen_failed_attempts( $order );
		foreach ( $failed_attempts as $attempt ) {
			// Unclear which field name is used; log rather than skip an attempt carrying neither.
			if ( ! isset( $attempt['transactionType'] ) && ! isset( $attempt['type'] ) ) {
				Swedbank_Pay()->logger()->error(
					"[ASYNC REVERSAL]: Failed attempt for order #{$order->get_order_number()} has no recognised type field: " . wp_json_encode( $attempt ),
					$context
				);
				continue;
			}

			$type = $attempt['transactionType'] ?? $attempt['type'];
			if ( OrderManagement::TYPE_REVERSAL !== $type ) {
				continue;
			}

			$number = (string) ( $attempt['number'] ?? '' );

			// Already handled on an earlier callback for this order.
			if ( '' !== $number && in_array( $number, $seen, true ) ) {
				continue;
			}

			// A failed attempt carries no payeeReference, so infer which entry it belongs to.
			$entry_reference = $this->match_pending_to_attempt( $pending, $attempt );

			if ( '' !== $number ) {
				$seen[] = $number;
			}

			if ( null === $entry_reference ) {
				continue;
			}

			$entry = $pending[ $entry_reference ];
			unset( $pending[ $entry_reference ] );
			$this->save_pending( $order, $pending );

			$this->handle_failed_reversal( $order, $entry_reference, $entry, $attempt );
		}
		$this->save_seen_failed_attempts( $order, $seen );

		if ( ! empty( $pending ) ) {
			Swedbank_Pay()->logger()->debug( '[ASYNC REVERSAL]: Reversal(s) still awaiting confirmation: ' . implode( ', ', array_keys( $pending ) ), $context );
		}

		return true;
	}

	/**
	 * Retrieve the post purchase failed attempts for a payment order.
	 *
	 * Note the lowercase p in "purchase"; the SDK's link stub docblock spells it differently to
	 * the API.
	 *
	 * @param \SwedbankPay\Checkout\WooCommerce\Swedbank_Pay_Api $api The API instance.
	 * @param \WC_Order                                          $order The order, used for logging.
	 * @param string                                             $payment_order_id The payment order ID.
	 *
	 * @return array|\WP_Error The failed attempts, or WP_Error on a failed request or unknown shape.
	 */
	private function get_failed_attempts( $api, \WC_Order $order, $payment_order_id ) {
		LogUtility::$title = "[ASYNC REVERSAL]: Retrieve postpurchasefailedattempts for order #{$order->get_order_number()}";
		$result            = $api->request( 'GET', "{$payment_order_id}/postpurchasefailedattempts" );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Erroring keeps the reversal pending; reading an unknown shape as "no failures" would lose it.
		if ( ! isset( $result['postpurchaseFailedAttempts']['postpurchaseFailedAttemptList'] ) ) {
			return new \WP_Error(
				'unexpected_response',
				'The postpurchasefailedattempts response did not contain postpurchaseFailedAttempts.postpurchaseFailedAttemptList.'
			);
		}

		$list = $result['postpurchaseFailedAttempts']['postpurchaseFailedAttemptList'];

		return is_array( $list ) ? $list : array();
	}

	/**
	 * Find the pending entry a failed attempt belongs to.
	 *
	 * @param array $pending The pending entries, keyed by payeeReference.
	 * @param array $attempt The failed attempt from Swedbank Pay.
	 *
	 * @return string|null The payeeReference of the matching entry, or null when none matches.
	 */
	private function match_pending_to_attempt( array $pending, array $attempt ) {
		$attempt_created = strtotime( $attempt['created'] ?? '' );

		$match         = null;
		$match_created = null;
		foreach ( $pending as $payee_reference => $entry ) {
			$entry_created = strtotime( $entry['created'] ?? '' );

			// Ignore attempts that predate the reversal; they belong to an earlier refund.
			if ( false !== $attempt_created && false !== $entry_created
				&& $attempt_created < $entry_created - self::FAILED_ATTEMPT_TOLERANCE
			) {
				continue;
			}

			if ( null === $match_created || ( false !== $entry_created && $entry_created < $match_created ) ) {
				$match         = (string) $payee_reference;
				$match_created = $entry_created;
			}
		}

		return $match;
	}

	/**
	 * Get the failed attempt numbers that have already been handled for an order.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return array
	 */
	private function get_seen_failed_attempts( \WC_Order $order ) {
		$seen = $order->get_meta( self::SEEN_FAILED_ATTEMPTS_META );

		return empty( $seen ) ? array() : array_map( 'strval', (array) $seen );
	}

	/**
	 * Persist the handled failed attempt numbers.
	 *
	 * @param \WC_Order $order The order.
	 * @param array     $seen The failed attempt numbers.
	 *
	 * @return void
	 */
	private function save_seen_failed_attempts( \WC_Order $order, array $seen ) {
		$order->update_meta_data( self::SEEN_FAILED_ATTEMPTS_META, array_values( array_unique( $seen ) ) );
		$order->save_meta_data();
	}

	/**
	 * Handle a reversal that Swedbank Pay reported as failed.
	 *
	 * Puts the order on hold to alert the merchant and removes the WooCommerce refund
	 * so that the order totals are correct and the refund can be retried.
	 *
	 * @param \WC_Order $order The order.
	 * @param string    $payee_reference The payee reference of the failed reversal.
	 * @param array     $entry The pending reversal entry.
	 * @param array     $attempt The failed attempt data from Swedbank Pay.
	 *
	 * @return void
	 */
	private function handle_failed_reversal( \WC_Order $order, $payee_reference, array $entry, array $attempt ) {
		$context = array(
			'action'           => 'handle_failed_reversal',
			'order_id'         => $order->get_id(),
			'order_number'     => $order->get_order_number(),
			'payment_order_id' => $order->get_meta( '_payex_paymentorder_id' ),
			'payee_reference'  => $payee_reference,
			'entry'            => $entry,
			'failed_attempt'   => $attempt,
		);

		Swedbank_Pay()->logger()->error( "[ASYNC REVERSAL]: Reversal failed for order #{$order->get_order_number()}. Failed attempt: " . wp_json_encode( $attempt ), $context );

		$message = sprintf(
			// translators: %s: refund amount.
			__( 'The refund of %s could not be completed by Swedbank Pay. No money has been returned to the customer. The order has been set to on hold so you can review it and try the refund again.', 'swedbank-pay-payment-menu' ),
			wc_price( $entry['amount'] / 100, array( 'currency' => $order->get_currency() ) )
		);

		$gateway = swedbank_pay_get_payment_method( $order );
		if ( $gateway && property_exists( $gateway, 'api' ) ) {
			$gateway->api->update_order_status( $order, 'on-hold', $order->get_transaction_id(), $message );
		} else {
			$order->update_status( 'on-hold', $message );
		}

		/**
		 * Whether to remove the WooCommerce refund when Swedbank Pay rejected the reversal.
		 *
		 * Defaults to keeping it, which is how the other Krokedil gateways behave: the refund
		 * stands, the order goes on hold, and the merchant decides what to do. Returning true
		 * removes the refund so the order totals match the money actually moved, at the cost
		 * of leaving any stock adjustments behind.
		 *
		 * @param bool      $delete Whether to delete the refund. Default false.
		 * @param \WC_Order $order The order the reversal failed for.
		 * @param array     $entry The pending reversal entry.
		 */
		$delete_refund = apply_filters( 'swedbank_pay_delete_failed_refund', false, $order, $entry );

		if ( ! $delete_refund ) {
			return;
		}

		if ( ! empty( $entry['refund_id'] ) ) {
			$refund = wc_get_order( $entry['refund_id'] );
			if ( $refund instanceof \WC_Order_Refund && $refund->get_parent_id() === $order->get_id() ) {
				$refund->delete( true );
				$order->add_order_note(
					__( 'The refund entry was removed from the order so the order totals are correct. If any items were returned to stock, please adjust the stock manually.', 'swedbank-pay-payment-menu' )
				);
			}
		}

		// Only roll back the plugin's own refunded-items tracking when the refund itself was
		// removed; otherwise the lines would be refundable again while the refund still stands.
		if ( 'items' === ( $entry['mode'] ?? '' ) && ! empty( $entry['lines'] ) ) {
			$this->rollback_refunded_items( $order, (array) $entry['lines'] );
		}
	}

	/**
	 * Subtract the given refund lines from the `_payex_refunded_items` meta.
	 *
	 * Inverse of Swedbank_Pay_Payment_Actions::save_refunded_items(); without this a failed
	 * items refund would permanently block the lines from being refunded again.
	 *
	 * @param \WC_Order $order The order.
	 * @param array     $lines The refund lines (keyed by order item ID, each with a 'qty').
	 *
	 * @return void
	 */
	private function rollback_refunded_items( \WC_Order $order, array $lines ) {
		$current_items = $order->get_meta( '_payex_refunded_items' );
		$current_items = empty( $current_items ) ? array() : (array) $current_items;
		if ( empty( $current_items ) ) {
			return;
		}

		foreach ( $lines as $item_id => $line ) {
			$qty = max( 1, (int) ( $line['qty'] ?? 1 ) );

			$item = $order->get_item( $item_id );
			if ( ! $item ) {
				continue;
			}

			switch ( $item->get_type() ) {
				case 'line_item':
					// The item is a WC_Order_Item_Product here, so get_product() is available.
					$product   = $item->get_product();
					$reference = $product ? $product->get_sku() : 'other';
					break;
				case 'shipping':
				case 'fee':
				case 'coupon':
					$reference = $item->get_type();
					break;
				default:
					$reference = 'other';
					break;
			}

			foreach ( $current_items as $key => &$current_item ) {
				if ( ( $current_item[ Swedbank_Pay_Order_Item::FIELD_REFERENCE ] ?? '' ) === $reference ) {
					$current_item[ Swedbank_Pay_Order_Item::FIELD_QTY ] -= $qty;
					if ( $current_item[ Swedbank_Pay_Order_Item::FIELD_QTY ] <= 0 ) {
						unset( $current_items[ $key ] );
					}
					break;
				}
			}
			unset( $current_item );
		}

		$order->update_meta_data( '_payex_refunded_items', array_values( $current_items ) );
		$order->save_meta_data();
	}

	/**
	 * Get the pending reversal entries for an order.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return array
	 */
	private function get_pending( \WC_Order $order ) {
		$pending = $order->get_meta( self::PENDING_REVERSALS_META );

		return empty( $pending ) ? array() : (array) $pending;
	}

	/**
	 * Persist the pending reversal entries, removing the meta when empty.
	 *
	 * @param \WC_Order $order The order.
	 * @param array     $pending The pending entries to save.
	 *
	 * @return void
	 */
	private function save_pending( \WC_Order $order, array $pending ) {
		if ( empty( $pending ) ) {
			$order->delete_meta_data( self::PENDING_REVERSALS_META );
		} else {
			$order->update_meta_data( self::PENDING_REVERSALS_META, $pending );
		}

		$order->save_meta_data();
	}
}
