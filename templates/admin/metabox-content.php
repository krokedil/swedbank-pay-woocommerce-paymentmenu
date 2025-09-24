<?php
/** @var Swedbank_Pay_Payment_Gateway_Checkout $gateway */
/** @var WC_Order $order */
/** @var array $info */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

?>
<div>
	<?php if ( isset( $info['paid']['number'] ) ) : ?>
		<strong><?php _e( 'Number', 'swedbank-pay-woocommerce-checkout' ); ?>
			:</strong> <?php echo esc_html( $info['paid']['number'] ); ?>
		<br/>
	<?php endif; ?>
	<?php if ( isset( $info['paid']['instrument'] ) ) : ?>
		<strong><?php _e( 'Instrument', 'swedbank-pay-woocommerce-checkout' ); ?>
			: </strong> <?php echo esc_html( $info['paid']['instrument'] ); ?>
		<br/>
	<?php endif; ?>
	<?php if ( isset( $info['paid']['transactionType'] ) ) : ?>
		<strong><?php _e( 'Transaction type', 'swedbank-pay-woocommerce-checkout' ); ?>
			: </strong> <?php echo esc_html( $info['paid']['transactionType'] ); ?>
		<br/>
	<?php endif; ?>
</div>
