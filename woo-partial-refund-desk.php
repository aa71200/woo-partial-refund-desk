<?php
/**
 * Plugin Name: CodexaWP Partial Refund Desk
 * Description: Preview and record manual line-item refunds on WooCommerce orders.
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace CodexaWP\RefundDesk;
defined( 'ABSPATH' ) || exit;
const OPERATION_META = '_codexawp_refund_operation';

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
			return new \WP_Error( 'refund_line', 'Choose a valid product line and quantity.' );
		}
		$remaining = $item->get_quantity() - abs( $order->get_qty_refunded_for_item( $item->get_id() ) );
		if ( $requested > $remaining ) {
			return new \WP_Error( 'refund_quantity', 'Requested quantity exceeds the refundable quantity.' );
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
		return new \WP_Error( 'refund_amount', 'The calculated refund is outside the remaining refundable amount.' );
	}
	return [ 'amount' => $money( $amount ), 'line_items' => $lines ];
}

function record( int $order_id, array $quantities, string $operation, string $expected_amount ): \WC_Order_Refund|\WP_Error {
	global $wpdb;
	if ( ! current_user_can( 'edit_shop_order', $order_id ) || ! wp_is_uuid( $operation, 4 ) ) {
		return new \WP_Error( 'refund_permission', 'Order editing access and a valid request identifier are required.' );
	}
	$lock = 'codexa.refund.' . md5( $wpdb->prefix . $order_id );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock ) ) ) {
		return new \WP_Error( 'refund_busy', 'The order is being edited. Retry shortly.' );
	}
	try {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return new \WP_Error( 'refund_order', 'Order not found.' );
		}
		foreach ( $order->get_refunds() as $existing ) {
			if ( $operation === $existing->get_meta( OPERATION_META ) ) {
				return $existing;
			}
		}
		$result = preview( $order, $quantities );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $result['amount'] !== $expected_amount ) {
			return new \WP_Error( 'refund_stale', 'The refundable amount changed. Preview the updated amount.' );
		}

		$stamp = static function ( \WC_Order_Refund $refund ) use ( $operation, $order_id ): void {
			if ( $order_id === $refund->get_parent_id() ) {
				$refund->update_meta_data( OPERATION_META, $operation );
			}
		};
		add_action( 'woocommerce_create_refund', $stamp );
		try {
			return wc_create_refund( $result + [
				'order_id' => $order_id, 'reason' => 'Manual line-item refund',
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

add_action( 'admin_menu', static function (): void {
	add_submenu_page( 'woocommerce', 'Partial Refund Desk', 'Partial Refund Desk', 'manage_woocommerce', 'codexawp-refunds', __NAMESPACE__ . '\page' );
} );

function page(): void {
	$order_id = absint( $_GET['order'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$order = $order_id && current_user_can( 'edit_shop_order', $order_id ) ? wc_get_order( $order_id ) : false;
	echo '<div class="wrap"><h1>Partial Refund Desk</h1>';
	echo '<form method="get"><input type="hidden" name="page" value="codexawp-refunds"><label>Order ID <input name="order" type="number" min="1" required value="' . esc_attr( (string) $order_id ) . '"></label> <button class="button">Load order</button></form>';
	if ( ! $order || 'shop_order' !== $order->get_type() ) {
		echo '</div>';
		return;
	}
	$quantities = [];
	$result = null;
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		check_admin_referer( 'codexawp_refund_' . $order_id );
		$quantities = quantities_from_post();
		if ( isset( $_POST['confirm_refund'] ) ) {
			$operation = sanitize_text_field( wp_unslash( $_POST['operation'] ?? '' ) );
			$amount = sanitize_text_field( wp_unslash( $_POST['expected_amount'] ?? '' ) );
			$refund = record( $order_id, $quantities, $operation, $amount );
			if ( is_wp_error( $refund ) ) {
				$result = $refund;
			} else {
				echo '<p>Manual refund recorded. Refund ID: ' . esc_html( (string) $refund->get_id() ) . '.</p></div>';
				return;
			}
		} else {
			$result = preview( $order, $quantities );
		}
	}
	echo '<form method="post">';
	wp_nonce_field( 'codexawp_refund_' . $order_id );
	foreach ( $order->get_items() as $item ) {
		$id = $item->get_id();
		$remaining = $item->get_quantity() - abs( $order->get_qty_refunded_for_item( $id ) );
		echo '<p><label>' . esc_html( $item->get_name() . ' — quantity to refund' ) . ' <input name="refund_qty[' . esc_attr( (string) $id ) . ']" type="number" min="0" max="' . esc_attr( (string) $remaining ) . '" value="' . esc_attr( (string) ( $quantities[$id] ?? 0 ) ) . '"></label></p>';
	}
	echo '<button class="button" name="preview_refund" value="1">Preview refund</button>';
	if ( is_wp_error( $result ) ) {
		echo '<p role="alert">' . esc_html( $result->get_error_message() ) . '</p>';
	} elseif ( $result ) {
		echo '<p>Refund total including tax: ' . wp_kses_post( wc_price( $result['amount'], [ 'currency' => $order->get_currency() ] ) ) . '</p>';
		echo '<input type="hidden" name="operation" value="' . esc_attr( wp_generate_uuid4() ) . '"><input type="hidden" name="expected_amount" value="' . esc_attr( $result['amount'] ) . '"> <button class="button button-primary" name="confirm_refund" value="1">Record manual refund</button>';
	}
	echo '</form></div>';
}
