<?php
/** Run only against a disposable local WordPress database. See README.md. */
ob_start(); // WordPress's cookie-auth nonce refresh sends a header during the test.
$site = $argv[1] ?? '';
if ( ! is_file( $site . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php tests/integration.php /path/to/disposable/wordpress\n" );
	exit( 1 );
}
require $site . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( 'local' !== wp_get_environment_type() || ! defined( 'UPOS_INTEGRATION_TESTS' ) || ! UPOS_INTEGRATION_TESTS ) {
	fwrite( STDERR, "Set WP_ENVIRONMENT_TYPE=local and UPOS_INTEGRATION_TESTS=true in the disposable wp-config.php.\n" );
	exit( 1 );
}
if ( ! class_exists( 'WooCommerce' ) ) {
	throw new RuntimeException( 'Activate WooCommerce first.' );
}
$result = activate_plugin( 'universal-woocommerce-pos/universal-woocommerce-pos.php' );
if ( is_wp_error( $result ) ) {
	throw new RuntimeException( $result->get_error_message() );
}
// A just-activated plugin missed plugins_loaded in this process.
\StarZarei\UniversalPOS\Core\Plugin::boot();

use StarZarei\UniversalPOS\Database\Schema;
use StarZarei\UniversalPOS\Core\Installer;
use StarZarei\UniversalPOS\Orders\SalesChannel;

$checks = 0;
function check( bool $condition, string $label ): void {
	global $checks;
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $label );
	}
	++$checks;
	echo "PASS: $label\n";
}
function request( string $path, array $params = array() ): WP_REST_Response {
	$request = new WP_REST_Request( 'GET', '/universal-pos/v1' . $path );
	$request->set_query_params( $params );
	return rest_get_server()->dispatch( $request );
}
$admin = get_user_by( 'login', 'upos_test_admin' );
if ( ! $admin || ! user_can( $admin, 'administrator' ) ) {
	throw new RuntimeException( 'A disposable administrator named upos_test_admin is required.' );
}
$product_ids = array();
$order_ids = array();
$cashier_id = 0;
$original_hpos = get_option( 'woocommerce_custom_orders_table_enabled', 'no' );
$original_sync = get_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
try {
	wp_set_current_user( $admin->ID );
	foreach ( Schema::TABLES as $name ) {
		$table = Schema::table( $name );
		check( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), "table $name exists with custom prefix" );
	}
	$table = Schema::table( 'payment_lines' );
	$wpdb->insert( $table, array( 'idempotency_key' => 'integration-once', 'order_id' => 1, 'amount' => '12.34567890', 'currency' => 'USD', 'method' => 'cash', 'created_by' => $admin->ID, 'created_at' => current_time( 'mysql', true ) ) );
	check( 1 === $wpdb->rows_affected, 'payment fixture inserted' );
	$previous = $wpdb->suppress_errors();
	$duplicate = $wpdb->insert( $table, array( 'idempotency_key' => 'integration-once', 'order_id' => 1, 'amount' => '12.34567890', 'currency' => 'USD', 'method' => 'cash', 'created_by' => $admin->ID, 'created_at' => current_time( 'mysql', true ) ) );
	$wpdb->suppress_errors( $previous );
	check( false === $duplicate, 'database rejects duplicate payment idempotency key' );
	Schema::migrate();
	Installer::upgrade();
	check( '12.34567890' === $wpdb->get_var( "SELECT amount FROM $table WHERE idempotency_key = 'integration-once'" ), 'repeat migration preserves decimal payment data' );
	$wpdb->delete( $table, array( 'idempotency_key' => 'integration-once' ) );
	check( Schema::VERSION === get_option( 'upos_db_version' ), 'schema version recorded' );
	require_once ABSPATH . WPINC . '/pomo/mo.php';
	$translations = new MO();
	check( $translations->import_from_file( UPOS_PATH . 'languages/universal-woocommerce-pos-fa_IR.mo' ) && 'صندوق‌دار فروشگاه' === $translations->translate( 'POS Cashier' ), 'Persian translation catalog loads successfully' );
	$GLOBALS['wp_rest_auth_cookie'] = true;
	$_SERVER['HTTP_X_WP_NONCE'] = 'invalid';
	$auth = rest_cookie_check_errors( null );
	check( is_wp_error( $auth ) && 'rest_cookie_invalid_nonce' === $auth->get_error_code(), 'WordPress rejects invalid cookie REST nonce' );
	$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
	check( true === rest_cookie_check_errors( null ), 'WordPress accepts valid cookie REST nonce' );
	unset( $_SERVER['HTTP_X_WP_NONCE'] );

	wp_set_current_user( 0 );
	check( 401 === request( '/store' )->get_status(), 'anonymous REST access denied' );
	$cashier_id = wp_insert_user( array( 'user_login' => 'upos_fixture_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'upos_cashier' ) );
	if ( is_wp_error( $cashier_id ) ) {
		throw new RuntimeException( $cashier_id->get_error_message() );
	}
	wp_set_current_user( $cashier_id );
	check( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_products' ), 'cashier has no WooCommerce administration capabilities' );
	check( 200 === request( '/store' )->get_status(), 'cashier can read store configuration' );
	check( 403 === request( '/orders/1' )->get_status(), 'cashier cannot read orders by default' );
	check( 400 === request( '/products', array( 'per_page' => 101 ) )->get_status(), 'pagination limit enforced' );

	wp_set_current_user( $admin->ID );
	$product = new WC_Product_Simple();
	$product->set_name( 'UPOS integration product' );
	$product->set_sku( 'upos-' . wp_generate_password( 8, false ) );
	$product->set_regular_price( '25.50' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 5 );
	$product->set_status( 'publish' );
	$product_ids[] = $product->save();
	check( '25.50' === request( '/products/' . $product->get_id() )->get_data()['price'], 'WooCommerce product visible through POS API' );
	$product->set_regular_price( '31.75' );
	$product->save();
	wc_update_product_stock( $product->get_id(), 3, 'set' );
	$data = request( '/products/' . $product->get_id() )->get_data();
	check( '31.75' === $data['price'] && 3 === $data['stock_quantity'], 'WooCommerce price and stock changes immediately visible' );
	$list = request( '/products', array( 'sku' => $product->get_sku() ) );
	check( $product->get_id() === $list->get_data()['products'][0]['id'], 'SKU search returns WooCommerce product' );
	check( '1' === $list->get_headers()['X-WP-Total'], 'product pagination reports matching count' );
	$parent = new WC_Product_Variable();
	$parent->set_name( 'UPOS variable' );
	$parent->set_status( 'publish' );
	$size = new WC_Product_Attribute();
	$size->set_name( 'size' );
	$size->set_options( array( 'large', 'small' ) );
	$size->set_variation( true );
	$parent->set_attributes( array( $size ) );
	$product_ids[] = $parent->save();
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent->get_id() );
	$variation->set_attributes( array( 'size' => 'large' ) );
	$variation->set_regular_price( '10' );
	$product_ids[] = $variation->save();
	$variants = request( '/products', array( 'parent' => $parent->get_id() ) )->get_data()['products'];
	check( 1 === count( $variants ) && $variation->get_id() === $variants[0]['id'], 'variation query reads child product' );
	check( 'large' === $variants[0]['attributes'][0]['option'], 'variation attributes serialized' );

	// Test WooCommerce's two actual order data stores, rather than only declaring compatibility.
	WC_Install::create_tables();
	update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
	foreach ( array( 'no', 'yes' ) as $hpos ) {
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos );
		$order = wc_create_order();
		$order_ids[] = $order->get_id();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->calculate_totals();
		$order->save();
		check( 'online' === wc_get_order( $order->get_id() )->get_meta( SalesChannel::META_KEY ), "new order defaults to online (HPOS $hpos)" );
		SalesChannel::mark_pos( $order, $cashier_id );
		$order->save();
		$data = request( '/orders/' . $order->get_id() )->get_data();
		check( 'pos' === $data['channel'] && $cashier_id === $data['cashier_id'], "POS channel and cashier survive CRUD reload (HPOS $hpos)" );
		$store = request( '/store' )->get_data();
		check( ( 'yes' === $hpos ) === $store['hpos_enabled'], "store reports actual HPOS state ($hpos)" );
		$store_class = $order->get_data_store()->get_current_class_name();
		check( ( 'yes' === $hpos ) === str_contains( $store_class, 'OrdersTableDataStore' ), "order uses expected physical data store (HPOS $hpos)" );
		$order->delete( true );
		array_pop( $order_ids );
	}
	check( 'no-store, private' === request( '/store' )->get_headers()['Cache-Control'], 'responses disallow price/stock caching' );
	check( 404 === request( '/products/999999999' )->get_status(), 'unknown product returns 404' );
	echo "Completed $checks checks.\n";
} finally {
	foreach ( $order_ids as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) { $order->delete( true ); }
	}
	update_option( 'woocommerce_custom_orders_table_enabled', $original_hpos );
	update_option( 'woocommerce_custom_orders_table_data_sync_enabled', $original_sync );
	foreach ( array_reverse( $product_ids ) as $id ) {
		$product = wc_get_product( $id );
		if ( $product ) { $product->delete( true ); }
	}
	$wpdb->delete( Schema::table( 'payment_lines' ), array( 'idempotency_key' => 'integration-once' ) );
	if ( $cashier_id && ! is_wp_error( $cashier_id ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $cashier_id );
	}
}
