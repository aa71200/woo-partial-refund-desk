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

if ( '--confirm' === ( $argv[1] ?? '' ) ) {
	[ $script, $command, $order_id, $item_id, $operation, $token, $user ] = $argv;
	wp_set_current_user( (int) $user );
	fwrite( STDOUT, "confirming\n" );
	fflush( STDOUT );
	$result = \CodexaWP\RefundDesk\record( (int) $order_id, [ (int) $item_id => 1 ], $operation, $token );
	echo ( is_wp_error( $result ) ? $result->get_error_code() : $result->get_id() ) . PHP_EOL;
	exit;
}

$admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0];
wp_set_current_user( $admin );
check( 42 === \CodexaWP\RefundDesk\requested_order_id( '0042' ), 'The lookup accepts a positive order ID' );
check( 0 === \CodexaWP\RefundDesk\requested_order_id( array( '42' ) ), 'The lookup rejects array input without selecting another order' );
check( 0 === \CodexaWP\RefundDesk\requested_order_id( '-42' ), 'The lookup does not convert a negative ID into a different order' );
$order = wc_create_order();
$item = new WC_Order_Item_Product();
$item->set_name( 'Workshop kit' );
$item->set_quantity( 3 );
$item->set_subtotal( 30 );
$item->set_total( 24 );
$item->set_taxes( [ 'total' => [ 1 => 2.4 ], 'subtotal' => [ 1 => 3 ] ] );
$order->add_item( $item );
$other = clone $item;
$other->set_id( 0 );
$other->set_name( 'Same-price spare kit' );
$order->add_item( $other );
$order->set_total( 52.8 );
$order->save();
$line = [ $item->get_id() => 1 ];
$review = \CodexaWP\RefundDesk\review( $order, $line );
check( ! is_wp_error( $review ) && '8.80' === $review['amount'], 'Preview uses discounted revenue plus tax' );
$operation = $review['operation'];
$other_line = [ $other->get_id() => 1 ];
$mismatch = \CodexaWP\RefundDesk\record( $order->get_id(), $other_line, $operation, $review['review'] );
check( is_wp_error( $mismatch ) && 'refund_stale' === $mismatch->get_error_code(), 'Another line with the same price cannot reuse the review' );
$worker = null;
$pipes = [];
$start_confirmation = static function () use ( $order, $item, $operation, $review, $admin, &$worker, &$pipes ): void {
	$worker = proc_open( [ PHP_BINARY, __FILE__, '--confirm', (string) $order->get_id(), (string) $item->get_id(), $operation, $review['review'], (string) $admin ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
	if ( ! is_resource( $worker ) || 'confirming' !== trim( (string) fgets( $pipes[1] ) ) ) {
		throw new RuntimeException( 'The independent confirmation worker did not start.' );
	}
};
add_action( 'woocommerce_create_refund', $start_confirmation, 20 );
try {
	$refund = \CodexaWP\RefundDesk\record( $order->get_id(), $line, $operation, $review['review'] );
	check( ! is_wp_error( $refund ) && is_resource( $worker ), 'Parallel confirmation started while saving the refund' );
	check( (string) $refund->get_id() === trim( (string) stream_get_contents( $pipes[1] ) ), 'Parallel confirmation received the same refund' );
} finally {
	remove_action( 'woocommerce_create_refund', $start_confirmation, 20 );
	if ( is_resource( $worker ) ) {
		foreach ( $pipes as $pipe ) {
			fclose( $pipe );
		}
		proc_close( $worker );
	}
}
check( ! is_wp_error( $refund ), is_wp_error( $refund ) ? $refund->get_error_message() : 'Manual refund persisted' );
$again = \CodexaWP\RefundDesk\record( $order->get_id(), $line, $operation, $review['review'] );
check( ! is_wp_error( $again ) && $refund->get_id() === $again->get_id(), 'Repeat confirmation reuses the refund' );
$fresh = wc_get_order( $order->get_id() );
check( count( $fresh->get_refunds() ) === 1, 'WooCommerce saved one refund only' );
check( is_wp_error( \CodexaWP\RefundDesk\preview( $fresh, [ $item->get_id() => 3 ] ) ), 'Prior refund reduces the allowed quantity' );
check( '8.80' === \CodexaWP\RefundDesk\preview( $fresh, $line )['amount'], 'Remaining line totals remain consistent' );
$old_review = \CodexaWP\RefundDesk\review( $fresh, $line );
$native = wc_create_refund( [ 'order_id' => $order->get_id(), 'amount' => '1.00' ] );
check( ! is_wp_error( $native ), 'Native refund created between review and confirmation' );
$stale = \CodexaWP\RefundDesk\record( $order->get_id(), $line, $old_review['operation'], $old_review['review'] );
check( is_wp_error( $stale ) && 'refund_stale' === $stale->get_error_code(), 'A native refund invalidates the signed review' );
echo 'Fixture order: ' . $order->get_id() . PHP_EOL;
