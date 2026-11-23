<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' || ! getenv( 'CODEXAWP_TEST_WP_LOAD' ) ) {
	throw new RuntimeException( 'Set CODEXAWP_TEST_WP_LOAD to a disposable WordPress installation.' );
}
require getenv( 'CODEXAWP_TEST_WP_LOAD' );
if ( 'local' !== wp_get_environment_type() || ! function_exists( 'WC' ) ) {
	throw new RuntimeException( 'This fixture requires local WordPress with WooCommerce active.' );
}

function check( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
	echo "PASS: $message\n";
}

$admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0];
wp_set_current_user( $admin );
$order = wc_create_order();
$item = new WC_Order_Item_Product();
$item->set_name( 'Workshop kit' );
$item->set_quantity( 3 );
$item->set_subtotal( 30 );
$item->set_total( 24 );
$item->set_taxes( [ 'total' => [ 1 => 2.4 ], 'subtotal' => [ 1 => 3 ] ] );
$order->add_item( $item );
$order->set_total( 26.4 );
$order->save();
$line = [ $item->get_id() => 1 ];
$review = \CodexaWP\RefundDesk\preview( $order, $line );
check( ! is_wp_error( $review ) && '8.80' === $review['amount'], 'Preview uses discounted revenue plus tax' );
$operation = wp_generate_uuid4();
$refund = \CodexaWP\RefundDesk\record( $order->get_id(), $line, $operation, $review['amount'] );
check( ! is_wp_error( $refund ), is_wp_error( $refund ) ? $refund->get_error_message() : 'Manual refund persisted' );
$again = \CodexaWP\RefundDesk\record( $order->get_id(), $line, $operation, $review['amount'] );
check( ! is_wp_error( $again ) && $refund->get_id() === $again->get_id(), 'Repeat confirmation reuses the refund' );
$fresh = wc_get_order( $order->get_id() );
check( count( $fresh->get_refunds() ) === 1, 'WooCommerce saved one refund only' );
check( is_wp_error( \CodexaWP\RefundDesk\preview( $fresh, [ $item->get_id() => 3 ] ) ), 'Prior refund reduces the allowed quantity' );
check( '8.80' === \CodexaWP\RefundDesk\preview( $fresh, $line )['amount'], 'Remaining line totals remain consistent' );
echo 'Fixture order: ' . $order->get_id() . PHP_EOL;
