<?php
/** Two independent PHP processes compete through real WooCommerce reservations/locks. */
ob_start();
$site = $argv[1] ?? '';
if ( ! is_file( $site . '/wp-load.php' ) ) { throw new RuntimeException( 'A disposable WordPress path is required.' ); }
require $site . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ! defined( 'UPOS_INTEGRATION_TESTS' ) || ! UPOS_INTEGRATION_TESTS ) { throw new RuntimeException( 'Disposable local site required.' ); }

use StarZarei\UniversalPOS\Database\Schema;
use Automattic\WooCommerce\Checkout\Helpers\ReserveStock;

add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'woocommerce_email_content_type', static fn() => 'text/plain' );

function execute_request( array $job ): array {
	wp_set_current_user( $job['actor'] );
	$r = new WP_REST_Request( 'POST', '/universal-pos/v1' . $job['route'] );
	$r->set_header( 'Content-Type', 'application/json' );
	$r->set_header( 'Idempotency-Key', $job['key'] );
	$r->set_body( wp_json_encode( $job['body'] ) );
	$response = rest_get_server()->dispatch( $r );
	return array( 'status' => $response->get_status(), 'data' => $response->get_data() );
}

if ( '--worker' === ( $argv[2] ?? '' ) ) {
	$file = $argv[3];
	$job = json_decode( file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
	file_put_contents( $file . '.ready', '1' );
	$deadline = microtime( true ) + 20;
	while ( ! is_file( $file . '.go' ) ) {
		if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'Worker barrier timed out.' ); }
		usleep( 10000 );
	}
	$result = execute_request( $job );
	ob_clean();
	echo wp_json_encode( $result );
	exit;
}

$checks = 0;
function assert_race( bool $value, string $label ): void {
	global $checks;
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$checks; echo "PASS: $label\n";
}
function race( array $jobs ): array {
	global $site;
	$workers = array(); $files = array();
	try {
		foreach ( $jobs as $job ) {
			$file = tempnam( sys_get_temp_dir(), 'upos-race-' ); $files[] = $file;
			file_put_contents( $file, wp_json_encode( $job ) );
			$command = array( PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, $site, '--worker', $file );
			$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot start worker.' ); }
			fclose( $pipes[0] );
			$workers[] = array( $process, $pipes, $file );
		}
		$deadline = microtime( true ) + 20;
		foreach ( $workers as [ $process, $pipes, $file ] ) {
			while ( ! is_file( $file . '.ready' ) ) {
				if ( microtime( true ) > $deadline || ! proc_get_status( $process )['running'] ) { throw new RuntimeException( 'Workers failed to reach barrier.' ); }
				usleep( 10000 );
			}
		}
		foreach ( $files as $file ) { file_put_contents( $file . '.go', '1' ); }
		$results = array();
		foreach ( $workers as [ $process, $pipes ] ) {
			$out = stream_get_contents( $pipes[1] ); $error = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] ); fclose( $pipes[2] );
			$exit = proc_close( $process );
			if ( 0 !== $exit || '' !== $error ) { throw new RuntimeException( "Worker failed ($exit): $error $out" ); }
			$results[] = json_decode( $out, true, 512, JSON_THROW_ON_ERROR );
		}
		$workers = array();
		return $results;
	} finally {
		foreach ( $workers as [ $process ] ) { if ( is_resource( $process ) ) { proc_terminate( $process ); proc_close( $process ); } }
		foreach ( $files as $file ) { foreach ( array( '', '.ready', '.go' ) as $suffix ) { if ( is_file( $file . $suffix ) ) { unlink( $file . $suffix ); } } }
	}
}

$original = get_option( 'woocommerce_custom_orders_table_enabled', 'no' );
$sync = get_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
$stock = get_option( 'woocommerce_manage_stock' ); $taxes = get_option( 'woocommerce_calc_taxes' );
$admin = get_user_by( 'login', 'upos_test_admin' );
if ( ! $admin ) { throw new RuntimeException( 'Missing test administrator.' ); }
$product_id = 0; $orders = array();
try {
	update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
	update_option( 'woocommerce_manage_stock', 'yes' ); update_option( 'woocommerce_calc_taxes', 'no' );
	foreach ( array( 'no', 'yes' ) as $hpos ) {
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos );
		$p = new WC_Product_Simple(); $p->set_name( 'Concurrent last unit' ); $p->set_regular_price( '5.00' ); $p->set_manage_stock( true ); $p->set_stock_quantity( 1 ); $product_id = $p->save();
		$body = array( 'items' => array( array( 'product_id' => $product_id, 'quantity' => 1 ) ), 'billing' => array( 'first_name' => 'Race', 'last_name' => 'Test', 'phone' => '09123456789' ) );
		$job = array( 'actor' => $admin->ID, 'route' => '/sales', 'body' => $body, 'key' => 'race-' . wp_generate_uuid4() );
		$other = array_merge( $job, array( 'key' => 'other-' . wp_generate_uuid4() ) );
		$results = race( array( $job, $other ) );
		$statuses = array_column( $results, 'status' ); sort( $statuses );
		foreach ( $results as $result ) { if ( 200 === $result['status'] ) { $orders[] = $result['data']['id']; } }
		assert_race( array( 200, 409 ) === $statuses, "two simultaneous baskets can reserve the last unit only once (HPOS $hpos): " . wp_json_encode( $results ) );
		wp_cache_flush();
		assert_race( 1 === ( new ReserveStock() )->get_reserved_stock( wc_get_product( $product_id ) ), 'competing orders leave one reserved unit' );
		$id = end( $orders );
		$pay = array( 'actor' => $admin->ID, 'route' => '/sales/' . $id . '/payments', 'body' => array( 'received' => true, 'expected_total' => '5.00', 'payments' => array( array( 'method' => 'cash', 'amount' => '5.00' ) ) ), 'key' => 'concurrent-payment-' . wp_generate_uuid4() );
		$paid = race( array( $pay, $pay ) );
		assert_race( array( 200, 200 ) === array_column( $paid, 'status' ) && $paid[0]['data']['id'] === $paid[1]['data']['id'], 'simultaneous payment retries return the same sale' );
		wp_cache_flush();
		assert_race( 0 === wc_get_product( $product_id )->get_stock_quantity(), 'simultaneous retries reduce stock once' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id = %d', Schema::table( 'payment_lines' ), $id ) );
		assert_race( 1 === $count, 'simultaneous retries record one payment line' );

		// Same prepare key must converge, rather than consuming the stock twice.
		wc_update_product_stock( $product_id, 1, 'set' );
		$job['key'] = 'same-prepare-' . wp_generate_uuid4();
		$same = race( array( $job, $job ) );
		foreach ( $same as $result ) { if ( 200 === $result['status'] ) { $orders[] = $result['data']['id']; } }
		assert_race( array( 200, 200 ) === array_column( $same, 'status' ) && $same[0]['data']['id'] === $same[1]['data']['id'], 'simultaneous prepare retries create one order' );
		wp_cache_flush(); // Child processes may reuse IDs rolled back by a competing transaction.
		wc_update_product_stock( $product_id, 20, 'set' );
		$coupon = new WC_Coupon(); $coupon->set_code( 'race-coupon-' . wp_generate_uuid4() ); $coupon->set_discount_type( 'percent' ); $coupon->set_amount( '10' ); $coupon->set_usage_limit( 1 ); $coupon->save();
		$discounted = $body; $discounted['coupon'] = $coupon->get_code();
		$first = array_merge( $job, array( 'body' => $discounted, 'key' => 'coupon-one-' . wp_generate_uuid4() ) );
		$second = array_merge( $first, array( 'key' => 'coupon-two-' . wp_generate_uuid4() ) );
		try {
			$limited = race( array( $first, $second ) );
			foreach ( $limited as $result ) { if ( 200 === $result['status'] ) { $orders[] = $result['data']['id']; } }
			$statuses = array_column( $limited, 'status' ); sort( $statuses );
			assert_race( array( 200, 409 ) === $statuses, 'simultaneous sales cannot exceed a single-use coupon: ' . wp_json_encode( $limited ) );
			wp_cache_flush();
			assert_race( 1 === ( new WC_Coupon( $coupon->get_id() ) )->get_usage_count(), 'concurrent coupon usage remains exactly one' );
		} finally { ( new WC_Coupon( $coupon->get_id() ) )->delete( true ); }
		$cart_key = 'race-parked-' . wp_generate_uuid4();
		$cart_job = array_merge( $job, array( 'route' => '/carts/' . $cart_key, 'body' => array( 'label' => 'Race basket', 'basket' => $body ) ) );
		$parked = execute_request( $cart_job );
		assert_race( 200 === $parked['status'], 'concurrency fixture saved a real parked basket' );
		$parked_body = $body; $parked_body['parked_cart_key'] = $cart_key;
		$first = array_merge( $job, array( 'body' => $parked_body, 'key' => 'parked-one-' . wp_generate_uuid4() ) );
		$second = array_merge( $first, array( 'key' => 'parked-two-' . wp_generate_uuid4() ) );
		$consumed = race( array( $first, $second ) );
		foreach ( $consumed as $result ) { if ( 200 === $result['status'] ) { $orders[] = $result['data']['id']; } }
		$statuses = array_column( $consumed, 'status' ); sort( $statuses );
		assert_race( array( 200, 409 ) === $statuses, 'two independent checkouts consume a parked basket only once' );
		$wpdb->delete( Schema::table( 'parked_carts' ), array( 'key_hash' => hash( 'sha256', $admin->ID . ':' . $cart_key ) ) );
		wp_cache_flush();
		foreach ( array_unique( $orders ) as $order_id ) {
			as_unschedule_all_actions( 'upos_expire_sale', array( 'order_id' => $order_id ), 'universal-pos' );
			$order = wc_get_order( $order_id ); if ( $order ) { ( new ReserveStock() )->release_stock_for_order( $order ); $order->delete( true ); }
			foreach ( array( 'sale_requests', 'payment_lines', 'financial_transactions', 'stock_movements', 'audit_logs' ) as $table ) { $wpdb->delete( Schema::table( $table ), array( 'order_id' => $order_id ) ); }
		}
		$orders = array(); wc_get_product( $product_id )->delete( true ); $product_id = 0;
	}
	echo "Completed $checks concurrency checks across independent connections.\n";
} finally {
	foreach ( array_unique( $orders ) as $id ) {
		as_unschedule_all_actions( 'upos_expire_sale', array( 'order_id' => $id ), 'universal-pos' );
		$order = wc_get_order( $id ); if ( $order ) { ( new ReserveStock() )->release_stock_for_order( $order ); $order->delete( true ); }
		foreach ( array( 'sale_requests', 'payment_lines', 'financial_transactions', 'stock_movements', 'audit_logs' ) as $table ) { $wpdb->delete( Schema::table( $table ), array( 'order_id' => $id ) ); }
	}
	if ( $product_id && wc_get_product( $product_id ) ) { wc_get_product( $product_id )->delete( true ); }
	update_option( 'woocommerce_custom_orders_table_enabled', $original ); update_option( 'woocommerce_custom_orders_table_data_sync_enabled', $sync );
	update_option( 'woocommerce_manage_stock', $stock ); update_option( 'woocommerce_calc_taxes', $taxes );
}
