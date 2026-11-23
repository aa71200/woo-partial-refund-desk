<?php
/**
 * Plugin Name: CodexaWP Partial Refund Desk
 * Description: Preview and record manual line-item refunds on WooCommerce orders.
 * Version: 1.0.1
 * Requires Plugins: woocommerce
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 * Text Domain: woo-partial-refund-desk
 */
declare(strict_types=1);

namespace CodexaWP\RefundDesk;
defined( 'ABSPATH' ) || exit;
const OPERATION_META = '_codexawp_refund_operation';
const REVIEW_META = '_codexawp_refund_review';

add_action( 'before_woocommerce_init', static function (): void {
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
} );

function preview( \WC_Order $order, array $quantities ): array|\WP_Error {
	$lines = [];
	$amount = 0;
	$decimals = wc_get_price_decimals();
	$minor = static fn( $value ): int => (int) round( wc_add_number_precision( (float) $value ) );
	$money = static fn( int $value ): string => wc_format_decimal( wc_remove_number_precision( $value ), $decimals );
	foreach ( $quantities as $key => $requested ) {
		$item = $order->get_item( $key );
		if ( ! is_int( $requested ) || $requested < 1 || ! $item instanceof \WC_Order_Item_Product || $item->get_order_id() !== $order->get_id() ) {
			return new \WP_Error( 'refund_line', __( 'Choose a valid product line and quantity.', 'woo-partial-refund-desk' ) );
		}
		$remaining = $item->get_quantity() - abs( $order->get_qty_refunded_for_item( $item->get_id() ) );
		if ( $requested > $remaining ) {
			return new \WP_Error( 'refund_quantity', __( 'Requested quantity exceeds the refundable quantity.', 'woo-partial-refund-desk' ) );
		}
		$prorate = static fn( int $value ): int => intdiv( max( 0, $value ) * $requested, $remaining );
		$net = $prorate( $minor( $item->get_total() ) - abs( $minor( $order->get_total_refunded_for_item( $item->get_id() ) ) ) );
		$taxes = [];
		$amount += $net;
		foreach ( $item->get_taxes()['total'] ?? [] as $rate => $tax ) {
			$tax_minor = $prorate( $minor( $tax ) - abs( $minor( $order->get_tax_refunded_for_item( $item->get_id(), $rate ) ) ) );
			$taxes[$rate] = $money( $tax_minor );
			$amount += $tax_minor;
		}
		$lines[$item->get_id()] = [ 'qty' => $requested, 'refund_total' => $money( $net ), 'refund_tax' => $taxes ];
	}
	if ( $amount < 1 || $amount > $minor( $order->get_remaining_refund_amount() ) ) {
		return new \WP_Error( 'refund_amount', __( 'The calculated refund is outside the remaining refundable amount.', 'woo-partial-refund-desk' ) );
	}
	return [ 'amount' => $money( $amount ), 'line_items' => $lines ];
}

function review_digest( \WC_Order $order, array $quantities, array $preview ): string {
	ksort( $quantities, SORT_NUMERIC );
	ksort( $preview['line_items'], SORT_NUMERIC );
	foreach ( $preview['line_items'] as &$line ) {
		ksort( $line['refund_tax'], SORT_NUMERIC );
	}
	unset( $line );
	$refunds = array_map( static fn( \WC_Order_Refund $refund ): int => $refund->get_id(), $order->get_refunds() );
	sort( $refunds, SORT_NUMERIC );
	return hash( 'sha256', wp_json_encode( [
		$order->get_currency(), $order->get_total(), $order->get_remaining_refund_amount(), $refunds, $quantities, $preview,
	] ) );
}

function review( \WC_Order $order, array $quantities ): array|\WP_Error {
	if ( ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
		return new \WP_Error( 'refund_permission', __( 'Order editing access is required.', 'woo-partial-refund-desk' ) );
	}
	$result = preview( $order, $quantities );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	ksort( $quantities, SORT_NUMERIC );
	$operation = wp_generate_uuid4();
	$payload = base64_encode( wp_json_encode( [
		'operation' => $operation,
		'order' => $order->get_id(),
		'user' => get_current_user_id(),
		'until' => time() + 10 * MINUTE_IN_SECONDS,
		'quantities' => $quantities,
		'digest' => review_digest( $order, $quantities, $result ),
	] ) );
	return $result + [ 'operation' => $operation, 'review' => $payload . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) ) ];
}

function record( int $order_id, array $quantities, string $operation, string $review ): \WC_Order_Refund|\WP_Error {
	global $wpdb;
	if ( ! current_user_can( 'edit_shop_order', $order_id ) || ! wp_is_uuid( $operation, 4 ) ) {
		return new \WP_Error( 'refund_permission', __( 'Order editing access and a valid request identifier are required.', 'woo-partial-refund-desk' ) );
	}
	$stale = new \WP_Error( 'refund_stale', __( 'The refund preview changed or expired. Review the selection again.', 'woo-partial-refund-desk' ) );
	[ $payload, $signature ] = array_pad( explode( '.', $review, 2 ), 2, '' );
	if ( ! hash_equals( hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) ), $signature ) ) {
		return $stale;
	}
	$intent = json_decode( (string) base64_decode( $payload, true ), true );
	ksort( $quantities, SORT_NUMERIC );
	if ( ! is_array( $intent ) || $intent['order'] !== $order_id || $intent['user'] !== get_current_user_id() ||
		$intent['operation'] !== $operation || $intent['quantities'] !== $quantities ) {
		return $stale;
	}
	$lock = 'codexa.refund.' . md5( $wpdb->prefix . $order_id );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock ) ) ) {
		return new \WP_Error( 'refund_busy', __( 'The order is being edited. Retry shortly.', 'woo-partial-refund-desk' ) );
	}
	try {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return new \WP_Error( 'refund_order', __( 'Order not found.', 'woo-partial-refund-desk' ) );
		}
		foreach ( $order->get_refunds() as $existing ) {
			if ( $operation === $existing->get_meta( OPERATION_META ) ) {
				return $intent['digest'] === $existing->get_meta( REVIEW_META ) ? $existing : $stale;
			}
		}
		if ( $intent['until'] < time() ) {
			return $stale;
		}
		$result = preview( $order, $quantities );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! hash_equals( $intent['digest'], review_digest( $order, $quantities, $result ) ) ) {
			return $stale;
		}

		$stamp = static function ( \WC_Order_Refund $refund ) use ( $operation, $order_id, $intent ): void {
			if ( $order_id === $refund->get_parent_id() ) {
				$refund->update_meta_data( OPERATION_META, $operation );
				$refund->update_meta_data( REVIEW_META, $intent['digest'] );
			}
		};
		add_action( 'woocommerce_create_refund', $stamp );
		try {
			return wc_create_refund( $result + [
				'order_id' => $order_id, 'reason' => __( 'Manual line-item refund', 'woo-partial-refund-desk' ),
				'refund_payment' => false, 'restock_items' => false,
			] );
		} finally {
			remove_action( 'woocommerce_create_refund', $stamp );
		}
	} finally {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}

function quantities_from_post(): array {
	$values = wp_unslash( $_POST['refund_qty'] ?? [] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$quantities = [];
	foreach ( is_array( $values ) ? $values : [] as $id => $quantity ) {
		if ( ctype_digit( (string) $id ) && is_string( $quantity ) && ctype_digit( $quantity ) && (int) $quantity > 0 ) {
			$quantities[(int) $id] = (int) $quantity;
		}
	}
	return $quantities;
}

function requested_order_id( mixed $value ): int {
	if ( ! is_string( $value ) || ! ctype_digit( $value ) ) {
		return 0;
	}
	return (int) filter_var( ltrim( $value, '0' ), FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
}

add_action( 'admin_menu', static function (): void {
	$title = __( 'Partial Refund Desk', 'woo-partial-refund-desk' );
	$hook = add_submenu_page( 'woocommerce', $title, $title, 'manage_woocommerce', 'codexawp-refunds', __NAMESPACE__ . '\page' );
	if ( $hook ) {
		add_action( 'load-' . $hook, static function (): void {
			wp_enqueue_style( 'codexawp-refund-desk', plugins_url( 'desk.css', __FILE__ ), [], '1.2.0' );
		} );
	}
} );

function page(): void {
	// This lookup only selects a record. The review and confirmation use a separate nonce.
	$order_id = requested_order_id( $_GET['order'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validate a positive integer in requested_order_id().
	$order = $order_id && current_user_can( 'edit_shop_order', $order_id ) ? wc_get_order( $order_id ) : false;
	echo '<div class="wrap codexawp-refund-desk"><header class="desk-header"><div><h1>' . esc_html__( 'Partial Refund Desk', 'woo-partial-refund-desk' ) . '</h1><p>' .
		esc_html__( 'Review line items and tax before recording a refund.', 'woo-partial-refund-desk' ) . '</p></div><span class="desk-mode">' .
		esc_html__( 'Manual refund', 'woo-partial-refund-desk' ) . '</span></header>';
	echo '<form class="desk-lookup" method="get"><input type="hidden" name="page" value="codexawp-refunds"><label class="desk-label">' .
		esc_html__( 'Order ID', 'woo-partial-refund-desk' ) . ' <input name="order" type="number" min="1" required value="' . esc_attr( (string) $order_id ) .
		'"></label> <button class="button">' . esc_html__( 'Load order', 'woo-partial-refund-desk' ) . '</button></form>';
	if ( ! $order || 'shop_order' !== $order->get_type() ) {
		echo '<div class="desk-card desk-empty" role="status"><h2>' . esc_html__(
			'Choose an order to review',
			'woo-partial-refund-desk'
		) . '</h2><p>' . esc_html__(
			'Enter a valid order ID to preview a partial refund.',
			'woo-partial-refund-desk'
		) . '</p></div></div>';
		return;
	}
	$quantities = [];
	$result = null;
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		check_admin_referer( 'codexawp_refund_' . $order_id );
		$quantities = quantities_from_post();
		if ( isset( $_POST['confirm_refund'] ) ) {
			$operation = sanitize_text_field( wp_unslash( $_POST['operation'] ?? '' ) );
			$token = sanitize_text_field( wp_unslash( $_POST['refund_review'] ?? '' ) );
			$refund = record( $order_id, $quantities, $operation, $token );
			if ( is_wp_error( $refund ) ) {
				$result = $refund;
			} else {
				/* translators: %d: Refund ID. */
				$message = sprintf( __( 'Manual refund recorded. Refund #%d.', 'woo-partial-refund-desk' ), $refund->get_id() );
				echo '<p class="desk-success" role="status">' . esc_html( $message ) . '</p></div>';
				return;
			}
		} else {
			$result = review( $order, $quantities );
		}
	}
	echo '<form class="desk-layout" method="post">';
	wp_nonce_field( 'codexawp_refund_' . $order_id );
	/* translators: %s: Order number. */
	$order_label = sprintf( __( 'Order #%s', 'woo-partial-refund-desk' ), $order->get_order_number() );
	echo '<section class="desk-card" aria-labelledby="desk-items"><span class="desk-order">' . esc_html( $order_label ) . '</span><h2 id="desk-items">' .
		esc_html__( 'Items to refund', 'woo-partial-refund-desk' ) . '</h2><p>' . esc_html__( 'Choose a quantity from the remaining units.', 'woo-partial-refund-desk' ) . '</p>';
	foreach ( $order->get_items() as $item ) {
		$id = $item->get_id();
		$remaining = $item->get_quantity() - abs( $order->get_qty_refunded_for_item( $id ) );
		/* translators: 1: Refundable quantity. 2: Originally ordered quantity. */
		$available = sprintf( __( '%1$d refundable · %2$d originally ordered', 'woo-partial-refund-desk' ), $remaining, $item->get_quantity() );
		/* translators: %s: Order item name. */
		$quantity_label = sprintf( __( 'Quantity to refund for %s', 'woo-partial-refund-desk' ), $item->get_name() );
		echo '<div class="desk-line"><div><strong>' . esc_html( $item->get_name() ) . '</strong><small>' . esc_html( $available ) . '</small></div>';
		echo '<label class="desk-label desk-quantity">' . esc_html__( 'Quantity', 'woo-partial-refund-desk' ) . '<input aria-label="' . esc_attr( $quantity_label ) . '" name="refund_qty[' . esc_attr( (string) $id ) . ']" type="number" min="0" max="' . esc_attr( (string) $remaining ) . '" value="' . esc_attr( (string) ( $quantities[$id] ?? 0 ) ) . '"></label></div>';
	}
	echo '<button class="button" name="preview_refund" value="1">' . esc_html__( 'Preview refund', 'woo-partial-refund-desk' ) . '</button></section>';
	echo '<section class="desk-card desk-review" aria-labelledby="desk-summary"><h2 id="desk-summary">' . esc_html__( 'Refund summary', 'woo-partial-refund-desk' ) . '</h2>';
	if ( is_wp_error( $result ) ) {
		echo '<p class="desk-error" role="alert">' . esc_html( $result->get_error_message() ) . '</p>';
	} elseif ( $result ) {
		$net = array_sum( array_column( $result['line_items'], 'refund_total' ) );
		$tax = array_sum( array_map( 'array_sum', array_column( $result['line_items'], 'refund_tax' ) ) );
		$currency = [ 'currency' => $order->get_currency() ];
		echo '<p>' . esc_html__( 'Refund total including tax', 'woo-partial-refund-desk' ) . '</p><strong class="desk-total">' . wp_kses_post( wc_price( $result['amount'], $currency ) ) . '</strong>';
		echo '<dl class="desk-breakdown"><div><dt>' . esc_html__( 'Item subtotal', 'woo-partial-refund-desk' ) . '</dt><dd>' . wp_kses_post( wc_price( $net, $currency ) ) .
			'</dd></div><div><dt>' . esc_html__( 'Tax', 'woo-partial-refund-desk' ) . '</dt><dd>' . wp_kses_post( wc_price( $tax, $currency ) ) . '</dd></div></dl>';
		echo '<input type="hidden" name="operation" value="' . esc_attr( $result['operation'] ) . '"><input type="hidden" name="refund_review" value="' . esc_attr( $result['review'] ) . '"> <button class="button button-primary" name="confirm_refund" value="1">' . esc_html__( 'Record manual refund', 'woo-partial-refund-desk' ) . '</button>';
	} else {
		echo '<p>' . esc_html__( 'Preview your selection to see the amount, tax and confirmation action.', 'woo-partial-refund-desk' ) . '</p>';
	}
	echo '</section></form></div>';
}
