<?php
/** Coupons, permissioned manual discounts, real barcodes and parked carts. Disposable site only. */
ob_start();
$site = $argv[1] ?? '';
if ( ! is_file( $site . '/wp-load.php' ) ) { throw new RuntimeException( 'Provide a disposable WordPress path.' ); }
require $site . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ! defined( 'UPOS_INTEGRATION_TESTS' ) || ! UPOS_INTEGRATION_TESTS ) { throw new RuntimeException( 'Disposable test site required.' ); }

use StarZarei\UniversalPOS\Database\Schema;
use StarZarei\UniversalPOS\POS\SaleException;
use StarZarei\UniversalPOS\Products\Barcode;

add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'woocommerce_email_content_type', static fn() => 'text/plain' );
$checks = 0; $users = array(); $products = array(); $coupons = array(); $orders = array(); $rate_id = 0;
function extra_check( bool $value, string $label ): void { global $checks; if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$checks; echo "PASS: $label\n"; }
function extra_request( string $method, string $path, array $body = array(), ?string $key = null ): WP_REST_Response {
	$r = new WP_REST_Request( $method, '/universal-pos/v1' . $path );
	if ( 'GET' === $method ) { $r->set_query_params( $body ); } else { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); }
	$r->set_header( 'Idempotency-Key', $key ?? 'extra-' . wp_generate_uuid4() );
	return rest_get_server()->dispatch( $r );
}
function extra_basket( int $product_id ): array { return array( 'items' => array( array( 'product_id' => $product_id, 'quantity' => 1 ) ), 'billing' => array( 'first_name' => 'Extra', 'last_name' => 'Test', 'phone' => '09123456789', 'email' => 'purchaser@example.test' ) ); }
function extra_prepare( array $body ): array {
	global $orders;
	$r = extra_request( 'POST', '/sales', $body );
	if ( 200 !== $r->get_status() ) { throw new RuntimeException( 'Cannot prepare: ' . wp_json_encode( $r->get_data() ) ); }
	$data = $r->get_data(); $orders[] = $data['id']; return $data;
}
function extra_pay( array $sale ): WP_REST_Response { return extra_request( 'POST', '/sales/' . $sale['id'] . '/payments', array( 'received' => true, 'expected_total' => $sale['total'], 'payments' => array( array( 'method' => 'cash', 'amount' => $sale['total'] ) ) ) ); }
function new_coupon( string $type, string $amount ): WC_Coupon {
	global $coupons;
	$c = new WC_Coupon(); $c->set_code( 'upos-extra-' . wp_generate_uuid4() ); $c->set_discount_type( $type ); $c->set_amount( $amount ); $coupons[] = $c->save(); return $c;
}
$admin = get_user_by( 'login', 'upos_test_admin' );
if ( ! $admin ) { throw new RuntimeException( 'Missing test administrator.' ); }
$originals = array();
foreach ( array( 'woocommerce_custom_orders_table_enabled', 'woocommerce_custom_orders_table_data_sync_enabled', 'woocommerce_manage_stock', 'woocommerce_calc_taxes', 'woocommerce_enable_coupons', 'woocommerce_price_num_decimals', 'upos_barcode_meta_key', 'woocommerce_prices_include_tax', 'woocommerce_tax_based_on', 'woocommerce_default_country' ) as $name ) { $originals[ $name ] = get_option( $name, null ); }
try {
	wp_set_current_user( $admin->ID );
	update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' ); update_option( 'woocommerce_manage_stock', 'yes' ); update_option( 'woocommerce_calc_taxes', 'no' ); update_option( 'woocommerce_enable_coupons', 'yes' ); update_option( 'woocommerce_price_num_decimals', '2' ); delete_option( 'upos_barcode_meta_key' );
	foreach ( array( 'one', 'two' ) as $name ) {
		$id = wp_insert_user( array( 'user_login' => 'upos_extras_' . $name . '_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password( 40 ), 'role' => 'upos_cashier' ) ); if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); } $users[] = $id;
	}
	$p = new WC_Product_Simple(); $p->set_name( 'Extras product' ); $p->set_regular_price( '100.00' ); $p->set_sku( 'EXTRA-' . wp_generate_uuid4() ); $p->set_manage_stock( true ); $p->set_stock_quantity( 50 ); $products[] = $p->save();
	$q = new WC_Product_Simple(); $q->set_name( 'Another extras product' ); $q->set_regular_price( '20.00' ); $products[] = $q->save();
	Barcode::assign( $p->get_id(), '000012340001' );
	extra_check( '000012340001' === wc_get_product( $p->get_id() )->get_meta( Barcode::meta_key(), true ), 'barcode persists in WooCommerce metadata with leading zeros' );
	$scan = extra_request( 'GET', '/products/scan', array( 'code' => '000012340001' ) ); extra_check( $p->get_id() === $scan->get_data()['id'], 'scanner resolves actual dedicated barcode' );
	try { Barcode::assign( $q->get_id(), '000012340001' ); extra_check( false, 'duplicate barcode rejected' ); } catch ( SaleException $error ) { extra_check( 409 === $error->status, 'duplicate barcode rejected' ); }
	try { Barcode::assign( $q->get_id(), $p->get_sku() ); extra_check( false, 'barcode/SKU conflict rejected' ); } catch ( SaleException $error ) { extra_check( 409 === $error->status, 'barcode cannot shadow another product SKU' ); }
	try { Barcode::validated_key( '_stock' ); extra_check( false, 'internal meta key protected' ); } catch ( SaleException $error ) { extra_check( 400 === $error->status, 'barcode settings cannot overwrite stock metadata' ); }
	try { Barcode::validated_key( '_edit_lock' ); extra_check( false, 'WordPress metadata protected' ); } catch ( SaleException $error ) { extra_check( 400 === $error->status, 'barcode settings cannot overwrite the native edit lock' ); }
	$q->update_meta_data( Barcode::meta_key(), '000012340001' ); $q->save();
	extra_check( 409 === extra_request( 'GET', '/products/scan', array( 'code' => '000012340001' ) )->get_status(), 'ambiguous externally imported barcodes stop scanning safely' );
	$q->delete_meta_data( Barcode::meta_key() ); $q->save();
	wp_set_current_user( $users[0] );
	try { Barcode::assign( $p->get_id(), 'forbidden' ); extra_check( false, 'cashier cannot assign barcodes' ); } catch ( SaleException $error ) { extra_check( 403 === $error->status, 'cashier cannot change product barcodes' ); }
	foreach ( array( 'no', 'yes' ) as $hpos ) {
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos );
		$body = extra_basket( $p->get_id() );
		$c = new_coupon( 'percent', '10' ); $body['coupon'] = $c->get_code();
		$sale = extra_prepare( $body ); extra_check( '90.00' === $sale['total'] && '10' === $sale['discount'], "native percent coupon adjusts real order (HPOS $hpos)" );
		extra_check( 1 === ( new WC_Coupon( $c->get_id() ) )->get_usage_count(), 'pending order records native coupon usage once' );
		extra_check( 200 === extra_pay( $sale )->get_status(), 'discounted order completes through native payment cycle' );
		extra_check( 1 === ( new WC_Coupon( $c->get_id() ) )->get_usage_count(), 'payment does not count coupon a second time' );
		$c = new_coupon( 'fixed_cart', '15' ); $c->set_usage_limit( 1 ); $c->save(); $body['coupon'] = $c->get_code();
		$limited = extra_prepare( $body );
		extra_check( '85.00' === $limited['total'], 'fixed cart coupon uses WooCommerce calculation' );
		extra_check( 409 === extra_request( 'POST', '/sales', $body )->get_status(), 'global usage limit blocks another pending POS order' );
		extra_request( 'POST', '/sales/' . $limited['id'] . '/cancel' );
		extra_check( 0 === ( new WC_Coupon( $c->get_id() ) )->get_usage_count(), 'cancellation restores native coupon availability' );
		$limited = extra_prepare( $body ); $c->set_date_expires( time() - 5 ); $c->save();
		extra_check( 409 === extra_pay( $limited )->get_status(), 'coupon expiry before payment blocks finalization' );
		extra_request( 'POST', '/sales/' . $limited['id'] . '/cancel' );
		$c = new_coupon( 'percent', '10' ); $c->set_email_restrictions( array( $admin->user_email ) ); $c->save(); $body['coupon'] = $c->get_code();
		extra_check( 409 === extra_request( 'POST', '/sales', $body )->get_status(), 'cashier or administrator email cannot satisfy purchaser restrictions' );
		$c = new_coupon( 'fixed_product', '5' ); $c->set_usage_limit_per_user( 1 ); $c->save(); $body['coupon'] = $c->get_code();
		$no_email = $body; $no_email['billing']['email'] = ''; extra_check( 400 === extra_request( 'POST', '/sales', $no_email )->get_status(), 'per-customer coupon cannot use an unidentifiable guest' );
		$per_user = extra_prepare( $body ); extra_check( '95.00' === $per_user['total'], 'fixed product coupon applies to guest with an email' );
		extra_check( 409 === extra_request( 'POST', '/sales', $body )->get_status(), 'guest per-email usage limit is enforced' );
		extra_request( 'POST', '/sales/' . $per_user['id'] . '/cancel' );
		$c = new_coupon( 'percent', '10' ); $c->set_minimum_amount( '150' ); $c->save(); $body['coupon'] = $c->get_code();
		extra_check( 409 === extra_request( 'POST', '/sales', $body )->get_status(), 'native coupon minimum spend is enforced' );
		$c->set_minimum_amount( '' ); $c->set_product_ids( array( $q->get_id() ) ); $c->save();
		extra_check( 409 === extra_request( 'POST', '/sales', $body )->get_status(), 'native coupon product restrictions are enforced' );
		$c->set_product_ids( array() ); $c->save(); $changed_policy = extra_prepare( $body ); $c->set_amount( '12' ); $c->save();
		extra_check( 409 === extra_pay( $changed_policy )->get_status(), 'coupon policy edits after quote require a new review' );
		extra_request( 'POST', '/sales/' . $changed_policy['id'] . '/cancel' );
		unset( $body['coupon'] ); $body['manual_discount'] = array( 'type' => 'percent', 'value' => '25', 'reason' => 'Authorized discount test' );
		extra_check( 403 === extra_request( 'POST', '/sales', $body )->get_status(), 'manual discount requires a separate capability' );
		get_user_by( 'id', $users[0] )->add_cap( 'upos_apply_discounts' ); wp_set_current_user( 0 ); wp_set_current_user( $users[0] );
		$manual = extra_prepare( $body ); extra_check( '75.00' === $manual['total'], 'authorized manual percentage uses native coupon allocation' );
		extra_check( $users[0] === (int) wc_get_order( $manual['id'] )->get_meta( '_upos_discount_actor', true ), 'manual discount records its authorizing actor' );
		get_user_by( 'id', $users[0] )->remove_cap( 'upos_apply_discounts' ); wp_set_current_user( 0 ); wp_set_current_user( $users[0] );
		extra_check( 403 === extra_pay( $manual )->get_status(), 'revoked discount permission is checked again before payment' );
		extra_request( 'POST', '/sales/' . $manual['id'] . '/cancel' );
		get_user_by( 'id', $users[0] )->add_cap( 'upos_apply_discounts' ); wp_set_current_user( 0 ); wp_set_current_user( $users[0] );
		$body['manual_discount'] = array( 'type' => 'amount', 'value' => '10.00', 'reason' => 'Amount test' ); $manual = extra_prepare( $body );
		extra_check( '90.00' === $manual['total'] && 200 === extra_pay( $manual )->get_status(), 'authorized fixed manual discount is paid and recorded' );
		$body['manual_discount']['value'] = '101.00'; extra_check( 400 === extra_request( 'POST', '/sales', $body )->get_status(), 'manual discount cannot exceed product value' );
		$body['manual_discount']['value'] = '10.00'; $body['coupon'] = $c->get_code(); extra_check( 400 === extra_request( 'POST', '/sales', $body )->get_status(), 'coupon and manual discount cannot silently stack' );
		unset( $body['manual_discount'], $body['coupon'] );
		$cart_key = 'parked-' . wp_generate_uuid4(); $draft = array( 'label' => 'Extras parked basket', 'basket' => array( 'items' => $body['items'] ) );
		$stock = wc_get_product( $p->get_id() )->get_stock_quantity();
		$save = extra_request( 'POST', '/carts/' . $cart_key, $draft ); extra_check( 200 === $save->get_status(), 'basket can be parked before customer details are entered' );
		extra_check( $stock === wc_get_product( $p->get_id() )->get_stock_quantity(), 'parking does not reduce or reserve stock' );
		extra_check( $cart_key === extra_request( 'POST', '/carts/' . $cart_key, $draft )->get_data()['key'], 'parking retry is idempotent' );
		$changed = $draft; $changed['label'] = 'Changed'; extra_check( 409 === extra_request( 'POST', '/carts/' . $cart_key, $changed )->get_status(), 'same parking key cannot overwrite a different payload' );
		$replacement = 'replacement-' . wp_generate_uuid4(); $replace = $draft; $replace['replaces'] = $cart_key;
		extra_check( 200 === extra_request( 'POST', '/carts/' . $replacement, $replace )->get_status(), 'resaving edits atomically replaces the original parked basket' );
		extra_check( 409 === extra_request( 'GET', '/carts/' . $cart_key )->get_status(), 'replaced parked snapshot cannot be checked out' ); $cart_key = $replacement;
		wp_set_current_user( $users[1] ); extra_check( 404 === extra_request( 'GET', '/carts/' . $cart_key )->get_status(), 'another cashier cannot restore parked customer data' ); wp_set_current_user( $users[0] );
		$restored = extra_request( 'GET', '/carts/' . $cart_key )->get_data(); extra_check( ! isset( $restored['basket']['items'][0]['price'] ), 'parked basket stores IDs and quantities without a parallel product price' );
		$p->set_regular_price( '123.45' ); $p->save();
		$body['parked_cart_key'] = $cart_key;
		$invalid_cart_sale = $body; $invalid_cart_sale['coupon'] = 'not-a-real-coupon-' . wp_generate_uuid4();
		extra_check( 409 === extra_request( 'POST', '/sales', $invalid_cart_sale )->get_status(), 'post-consumption coupon failure rejects checkout' );
		extra_check( 200 === extra_request( 'GET', '/carts/' . $cart_key )->get_status(), 'failed checkout rolls back the source basket consumption' );
		$resumed = extra_prepare( $body );
		extra_check( '123.45' === $resumed['total'], 'resumed basket is repriced from current WooCommerce product data' ); $p->set_regular_price( '100.00' ); $p->save();
		extra_check( 409 === extra_request( 'GET', '/carts/' . $cart_key )->get_status(), 'prepared parked basket is consumed once' );
		extra_check( 409 === extra_request( 'POST', '/sales', $body )->get_status(), 'a different checkout key cannot consume the same parked basket twice' );
		$payload = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM %i WHERE key_hash = %s', Schema::table( 'parked_carts' ), hash( 'sha256', $users[0] . ':' . $cart_key ) ) ); extra_check( '{}' === $payload, 'consumption removes parked customer payload' );
		extra_request( 'POST', '/sales/' . $resumed['id'] . '/cancel' ); unset( $body['parked_cart_key'] );
		$discard = 'discard-' . wp_generate_uuid4(); extra_request( 'DELETE', '/carts/' . $discard );
		extra_check( 409 === extra_request( 'POST', '/carts/' . $discard, $draft )->get_status(), 'deleting an unknown parked attempt prevents a delayed save' );
		$expired = 'expired-' . wp_generate_uuid4(); extra_request( 'POST', '/carts/' . $expired, $draft );
		$wpdb->update( Schema::table( 'parked_carts' ), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'key_hash' => hash( 'sha256', $users[0] . ':' . $expired ) ) );
		extra_check( 409 === extra_request( 'GET', '/carts/' . $expired )->get_status(), 'expired parked customer data cannot be restored' );
		do_action( 'upos_purge_parked_carts' );
		extra_check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT key_hash FROM %i WHERE key_hash = %s', Schema::table( 'parked_carts' ), hash( 'sha256', $users[0] . ':' . $expired ) ) ), 'retention job removes expired parked payloads' );
		$rate_id = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'US', 'tax_rate_state' => 'CA', 'tax_rate' => '10.0000', 'tax_rate_name' => 'Extras tax', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_class' => 'upos-extra-tax' ) );
		add_filter( 'woocommerce_tax_classes', $tax_filter = static fn( $classes ) => array_merge( $classes, array( 'upos-extra-tax' ) ) );
		$taxed = new WC_Product_Simple(); $taxed->set_name( 'Discount tax fixture' ); $taxed->set_regular_price( '100' ); $taxed->set_tax_class( 'upos-extra-tax' ); $products[] = $taxed->save();
		update_option( 'woocommerce_calc_taxes', 'yes' ); update_option( 'woocommerce_tax_based_on', 'billing' ); update_option( 'woocommerce_default_country', 'US:CA' ); update_option( 'woocommerce_prices_include_tax', 'no' );
		$tax_body = extra_basket( $taxed->get_id() ); $tax_body['billing']['country'] = 'US'; $tax_body['billing']['state'] = 'CA'; $tax_body['manual_discount'] = array( 'type' => 'amount', 'value' => '10.00', 'reason' => 'Tax test' );
		$tax_sale = extra_prepare( $tax_body ); extra_check( '99.00' === $tax_sale['total'] && 9.0 === (float) $tax_sale['tax'], 'manual fixed discount reduces tax-exclusive taxable base natively' );
		extra_request( 'POST', '/sales/' . $tax_sale['id'] . '/cancel' );
		update_option( 'woocommerce_prices_include_tax', 'yes' ); $taxed->set_regular_price( '110' ); $taxed->save();
		$tax_sale = extra_prepare( $tax_body ); extra_check( '100.00' === $tax_sale['total'], 'manual fixed discount preserves tax-inclusive final amount' ); extra_request( 'POST', '/sales/' . $tax_sale['id'] . '/cancel' );
		remove_filter( 'woocommerce_tax_classes', $tax_filter ); WC_Tax::_delete_tax_rate( $rate_id ); $rate_id = 0; update_option( 'woocommerce_calc_taxes', 'no' ); update_option( 'woocommerce_prices_include_tax', 'no' );
		foreach ( $orders as $id ) {
			as_unschedule_all_actions( 'upos_expire_sale', array( 'order_id' => $id ), 'universal-pos' ); $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); }
			foreach ( array( 'sale_requests', 'payment_lines', 'financial_transactions', 'stock_movements', 'audit_logs' ) as $table ) { $wpdb->delete( Schema::table( $table ), array( 'order_id' => $id ) ); }
		}
		$orders = array(); get_user_by( 'id', $users[0] )->remove_cap( 'upos_apply_discounts' ); wp_set_current_user( 0 ); wp_set_current_user( $users[0] );
	}
	echo "Completed $checks POS extras checks.\n";
} finally {
	if ( $rate_id ) { WC_Tax::_delete_tax_rate( $rate_id ); }
	foreach ( $orders as $id ) { as_unschedule_all_actions( 'upos_expire_sale', array( 'order_id' => $id ), 'universal-pos' ); $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } foreach ( array( 'sale_requests', 'payment_lines', 'financial_transactions', 'stock_movements', 'audit_logs' ) as $table ) { $wpdb->delete( Schema::table( $table ), array( 'order_id' => $id ) ); } }
	foreach ( $coupons as $id ) { $coupon = new WC_Coupon( $id ); $coupon->delete( true ); }
	foreach ( array_reverse( $products ) as $id ) { $product = wc_get_product( $id ); if ( $product ) { $product->delete( true ); } }
	require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $users as $id ) { $wpdb->delete( Schema::table( 'parked_carts' ), array( 'created_by' => $id ) ); wp_delete_user( $id ); }
	foreach ( $originals as $name => $value ) { null === $value ? delete_option( $name ) : update_option( $name, $value ); }
}
