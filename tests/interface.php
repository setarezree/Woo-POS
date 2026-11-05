<?php
/** API recovery, UI permissions and locale integration; disposable site only. */
ob_start();
$site = $argv[1] ?? '';
if ( ! is_file( $site . '/wp-load.php' ) ) { throw new RuntimeException( 'A disposable WordPress path is required.' ); }
require $site . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ! defined( 'UPOS_INTEGRATION_TESTS' ) || ! UPOS_INTEGRATION_TESTS ) { throw new RuntimeException( 'Disposable local test site required.' ); }

use StarZarei\UniversalPOS\POS\App;
use StarZarei\UniversalPOS\POS\Strings;
use StarZarei\UniversalPOS\Database\Schema;

$checks = 0;
function ensure( bool $condition, string $label ): void { global $checks; if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$checks; echo "PASS: $label\n"; }
function call_interface( string $method, string $route, array $params = array(), string $key = '' ): WP_REST_Response {
	$r = new WP_REST_Request( $method, '/universal-pos/v1' . $route );
	if ( 'GET' === $method ) { $r->set_query_params( $params ); } else { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $params ) ); }
	if ( $key ) { $r->set_header( 'Idempotency-Key', $key ); }
	return rest_get_server()->dispatch( $r );
}
$users = array(); $products = array(); $orders = array(); $language = get_option( 'upos_ui_language', null );
try {
	update_option( 'upos_ui_language', 'fa_IR' );
	ensure( 'صندوق فروش' === Strings::t( 'Point of sale' ), 'Persian interface translations work independently of site language packs' );
	update_option( 'upos_ui_language', 'en_US' );
	ensure( 'Point of sale' === Strings::t( 'Point of sale' ), 'English interface language returns English strings' );
	update_option( 'upos_ui_language', 'fa_IR' );
	wp_set_current_user( 0 ); ensure( ! App::allowed(), 'standalone cashier denies anonymous users' );
	foreach ( array( 'one', 'two' ) as $name ) {
		$id = wp_insert_user( array( 'user_login' => 'upos_interface_' . $name . '_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password( 40 ), 'role' => 'upos_cashier' ) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); } $users[] = $id;
	}
	wp_set_current_user( $users[0] ); ensure( App::allowed(), 'cashier capabilities permit standalone interface' );
	$config = ( new App() )->config(); ensure( wp_verify_nonce( $config['nonce'], 'wp_rest' ) !== false, 'interface receives a native REST CSRF nonce' );
	get_user_by( 'id', $users[0] )->add_cap( 'upos_view_inventory', false );
	wp_set_current_user( 0 ); wp_set_current_user( $users[0] );
	ensure( ! App::allowed(), 'revoking inventory capability also blocks the standalone screen' );
	get_user_by( 'id', $users[0] )->remove_cap( 'upos_view_inventory' );
	wp_set_current_user( 0 ); wp_set_current_user( $users[0] );
	$p = new WC_Product_Simple(); $p->set_name( 'Interface recovery fixture' ); $p->set_regular_price( '3.00' ); $p->set_sku( 'interface-' . wp_generate_uuid4() ); $products[] = $p->save();
	$scan = call_interface( 'GET', '/products/scan', array( 'code' => $p->get_sku() ) );
	ensure( $p->get_id() === $scan->get_data()['id'], 'exact SKU lookup uses native WooCommerce SKU data' );
	ensure( 404 === call_interface( 'GET', '/products/scan', array( 'code' => 'missing-sku-' . wp_generate_uuid4() ) )->get_status(), 'missing SKU returns 404 instead of a global product' );
	$body = array( 'items' => array( array( 'product_id' => $p->get_id(), 'quantity' => 1 ) ), 'billing' => array( 'first_name' => 'Interface', 'last_name' => 'Test', 'phone' => '09123456789' ) );
	$key = 'interface-' . wp_generate_uuid4();
	ensure( 404 === call_interface( 'GET', '/sales/lookup', array( 'key' => $key ) )->get_status(), 'uncommitted preparation cannot be invented by recovery' );
	$sale = call_interface( 'POST', '/sales', $body, $key ); ensure( 200 === $sale->get_status(), 'interface service prepares real sale' );
	$id = $sale->get_data()['id']; $orders[] = $id;
	ensure( $id === call_interface( 'GET', '/sales/lookup', array( 'key' => $key ) )->get_data()['id'], 'recovery resolves the recorded prepare key to the existing order' );
	ensure( $id === call_interface( 'POST', '/sales/abandon', array( 'key' => $key ) )->get_data()['id'], 'abandon cannot discard an already committed preparation' );
	ensure( $id === call_interface( 'GET', '/sales/recent' )->get_data()['sales'][0]['id'], 'recent list contains this cashier real sale' );
	wp_set_current_user( $users[1] );
	ensure( array() === call_interface( 'GET', '/sales/recent' )->get_data()['sales'], 'recent list excludes another cashier sales' );
	ensure( 404 === call_interface( 'GET', '/sales/lookup', array( 'key' => $key ) )->get_status(), 'recovery key is scoped to its cashier' );
	wp_set_current_user( $users[0] );
	$abandoned_key = 'abandoned-' . wp_generate_uuid4();
	ensure( true === call_interface( 'POST', '/sales/abandon', array( 'key' => $abandoned_key ) )->get_data()['abandoned'], 'unknown prepare attempt can be durably abandoned' );
	ensure( true === call_interface( 'POST', '/sales/abandon', array( 'key' => $abandoned_key ) )->get_data()['abandoned'], 'abandon is idempotent' );
	ensure( 409 === call_interface( 'POST', '/sales', $body, $abandoned_key )->get_status(), 'late prepare request cannot create an order after abandonment' );
	ensure( 1 === count( call_interface( 'GET', '/sales/recent' )->get_data()['sales'] ), 'abandoned attempts are not shown as sales' );
	ensure( ! str_contains( wp_json_encode( $config ), 'DB_PASSWORD' ), 'interface configuration contains no database credential' );
	echo "Completed $checks interface checks.\n";
} finally {
	foreach ( $orders as $id ) {
		as_unschedule_all_actions( 'upos_expire_sale', array( 'order_id' => $id ), 'universal-pos' );
		$order = wc_get_order( $id ); if ( $order ) { ( new Automattic\WooCommerce\Checkout\Helpers\ReserveStock() )->release_stock_for_order( $order ); $order->delete( true ); }
		foreach ( array( 'payment_lines', 'financial_transactions', 'stock_movements', 'audit_logs' ) as $table ) { $wpdb->delete( Schema::table( $table ), array( 'order_id' => $id ) ); }
	}
	foreach ( $products as $id ) { $product = wc_get_product( $id ); if ( $product ) { $product->delete( true ); } }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $id ) { $wpdb->delete( Schema::table( 'sale_requests' ), array( 'created_by' => $id ) ); wp_delete_user( $id ); }
	null === $language ? delete_option( 'upos_ui_language' ) : update_option( 'upos_ui_language', $language );
}
