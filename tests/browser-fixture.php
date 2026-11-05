<?php
/** Disposable real-store fixtures for browser tests. Credentials stay outside the checkout. */
$site = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$file = $argv[3] ?? '';
if ( ! is_file( $site . '/wp-load.php' ) || ! $file ) { throw new RuntimeException( 'Usage: php tests/browser-fixture.php /path/to/wordpress configure|create|cleanup /outside/checkout/fixture.json' ); }
require $site . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ! defined( 'UPOS_INTEGRATION_TESTS' ) || ! UPOS_INTEGRATION_TESTS ) { throw new RuntimeException( 'A disposable local WordPress installation is required.' ); }

use StarZarei\UniversalPOS\Database\Schema;
use Automattic\WooCommerce\Checkout\Helpers\ReserveStock;
use StarZarei\UniversalPOS\Products\Barcode;

if ( 'configure' === $mode ) {
	if ( is_file( $file ) ) { throw new RuntimeException( 'Preserve and clean up the existing fixtures first.' ); }
	$state = array( 'options' => array(), 'products' => array(), 'users' => array(), 'coupons' => array() );
	foreach ( array( 'home', 'siteurl', 'blogname', 'upos_ui_language', 'woocommerce_calc_taxes', 'woocommerce_manage_stock', 'woocommerce_price_num_decimals', 'upos_manual_payment_methods', 'woocommerce_enable_coupons', 'upos_barcode_meta_key' ) as $name ) { $state['options'][ $name ] = get_option( $name, null ); }
	file_put_contents( $file, wp_json_encode( $state ) ); chmod( $file, 0600 );
	update_option( 'home', 'http://127.0.0.1:8093' ); update_option( 'siteurl', 'http://127.0.0.1:8093' );
	update_option( 'blogname', 'فروشگاه آزمایشی' ); update_option( 'upos_ui_language', 'fa_IR' );
	update_option( 'woocommerce_calc_taxes', 'no' ); update_option( 'woocommerce_manage_stock', 'yes' ); update_option( 'woocommerce_price_num_decimals', '2' );
	update_option( 'upos_manual_payment_methods', array( 'cash', 'card_terminal', 'bank_transfer' ) );
	update_option( 'woocommerce_enable_coupons', 'yes' ); delete_option( 'upos_barcode_meta_key' );
	echo "Disposable site configured.\n"; exit;
}

if ( ! is_file( $file ) ) { throw new RuntimeException( 'Configure the disposable site first.' ); }
$state = json_decode( file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
if ( in_array( $mode, array( 'english', 'persian' ), true ) ) {
	update_option( 'upos_ui_language', 'english' === $mode ? 'en_US' : 'fa_IR' );
	echo "Disposable interface language updated.\n"; exit;
}
if ( 'create' === $mode ) {
	if ( $state['products'] || $state['users'] ) { throw new RuntimeException( 'Fixtures already exist.' ); }
	$save = static function () use ( $file, &$state ) { file_put_contents( $file, wp_json_encode( $state ) ); };
	foreach ( array( 'cashier' => 'upos_cashier', 'other' => 'upos_cashier', 'subscriber' => 'subscriber', 'manager' => 'administrator' ) as $name => $role ) {
		$id = wp_insert_user( array( 'user_login' => 'upos_browser_' . $name . '_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password( 40 ), 'display_name' => 'cashier' === $name ? 'صندوق‌دار آزمایشی' : $name, 'role' => $role ) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
		$token = WP_Session_Tokens::get_instance( $id )->create( time() + 3600 );
		$state['users'][ $name ] = array( 'id' => $id, 'cookie' => wp_generate_auth_cookie( $id, time() + 3600, 'logged_in', $token ), 'authCookie' => wp_generate_auth_cookie( $id, time() + 3600, 'auth', $token ) ); $save();
	}
	$state['cookieName'] = LOGGED_IN_COOKIE;
	$state['authCookieName'] = AUTH_COOKIE;
	$names = array( 'پیراهن لینن', 'دفتر خط‌دار', 'فنجان سرامیکی', 'کیف پارچه‌ای', 'جعبهٔ هدیه', 'خودکار فلزی' );
	foreach ( $names as $i => $name ) {
		$p = new WC_Product_Simple(); $p->set_name( $name ); $p->set_sku( 'UI-' . $i ); $p->set_regular_price( (string) ( 20 + $i * 5 ) . '.50' ); $p->set_manage_stock( true ); $p->set_stock_quantity( 10 );
		$state['products'][] = $p->save(); $save();
	}
	$p = new WC_Product_Variable(); $p->set_name( 'تیشرت ساده' ); $p->set_sku( 'UI-VARIABLE' ); $p->set_manage_stock( true ); $p->set_stock_quantity( 5 );
	$attribute = new WC_Product_Attribute(); $attribute->set_name( 'size' ); $attribute->set_options( array( 'large', 'small' ) ); $attribute->set_variation( true ); $p->set_attributes( array( $attribute ) );
	$state['variable'] = $p->save(); $state['products'][] = $state['variable']; $save();
	foreach ( array( 'large', 'small' ) as $size ) {
		$v = new WC_Product_Variation(); $v->set_parent_id( $state['variable'] ); $v->set_attributes( array( 'size' => $size ) ); $v->set_regular_price( '35.00' ); $v->set_sku( 'UI-SIZE-' . strtoupper( $size ) );
		$state['products'][] = $v->save(); $save();
	}
	WC_Product_Variable::sync( $state['variable'] );
	wp_set_current_user( $state['users']['manager']['id'] );
	Barcode::assign( $state['products'][0], '000055550001' );
	$state['barcode'] = '000055550001';
	$coupon = new WC_Coupon(); $coupon->set_code( 'ui-coupon-' . wp_generate_uuid4() ); $coupon->set_discount_type( 'percent' ); $coupon->set_amount( '10' ); $state['coupons'][] = $coupon->save(); $state['coupon'] = $coupon->get_code();
	$save();
	$mg = new WC_Product_Simple(); $mg->set_name( 'کالای آزمون انبار' ); $mg->set_sku( 'UI-MANAGEMENT' ); $mg->set_regular_price( '120.00' ); $mg->set_manage_stock( true ); $mg->set_stock_quantity( 40 ); $mg->save(); $state['products'][] = $mg->get_id(); $state['management_product'] = $mg->get_id(); $save();
	$state['management_orders'] = array();
	foreach ( array( true, false ) as $paid ) {
		$o = wc_create_order(); $state['management_orders'][] = $o->get_id(); $save(); $o->set_billing_first_name( 'مشتری' ); $o->set_billing_last_name( 'آزمون مرجوعی' ); $o->set_billing_phone( '09123334455' ); $o->set_payment_method( 'cod' ); $o->set_payment_method_title( 'دریافت آزمون ووکامرس' ); $o->add_product( wc_get_product( $mg->get_id() ), $paid ? 3 : 1 ); $o->calculate_totals(); $o->save(); if ( $paid ) { $o->payment_complete(); }
	}
	$state['baseUrl'] = home_url( '/' ); $state['hpos'] = get_option( 'woocommerce_custom_orders_table_enabled', 'no' ); $save();
	echo "Real WooCommerce browser fixtures created.\n"; exit;
}

if ( 'cleanup' !== $mode ) { throw new RuntimeException( 'Unknown fixture operation.' ); }
foreach ( $state['users'] as $user ) {
	$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM %i WHERE created_by = %d AND order_id > 0', Schema::table( 'sale_requests' ), $user['id'] ) );
	if ( $user['id'] === ( $state['users']['manager']['id'] ?? 0 ) ) { $ids = array_merge( $ids, $state['management_orders'] ?? array() ); }
	foreach ( array_unique( $ids ) as $id ) {
		as_unschedule_all_actions( 'upos_expire_sale', array( 'order_id' => (int) $id ), 'universal-pos' );
		$order = wc_get_order( $id ); if ( $order ) { ( new ReserveStock() )->release_stock_for_order( $order ); foreach ( $order->get_refunds() as $refund ) { $refund->delete( true ); } $order->delete( true ); }
		foreach ( array( 'payment_lines', 'financial_transactions', 'stock_movements', 'audit_logs' ) as $table ) { $wpdb->delete( Schema::table( $table ), array( 'order_id' => $id ) ); }
	}
	$wpdb->delete( Schema::table( 'sale_requests' ), array( 'created_by' => $user['id'] ) );
	$wpdb->delete( Schema::table( 'operation_requests' ), array( 'created_by' => $user['id'] ) );
	$wpdb->delete( Schema::table( 'parked_carts' ), array( 'created_by' => $user['id'] ) );
}
foreach ( $state['coupons'] ?? array() as $id ) { ( new WC_Coupon( $id ) )->delete( true ); }
foreach ( array_reverse( $state['products'] ) as $id ) { $product = wc_get_product( $id ); if ( $product ) { $product->delete( true ); } $wpdb->delete( Schema::table( 'stock_movements' ), array( 'product_id' => $id ) ); $wpdb->delete( Schema::table( 'stock_movements' ), array( 'variation_id' => $id ) ); $wpdb->delete( Schema::table( 'audit_logs' ), array( 'object_type' => 'product', 'object_id' => $id ) ); }
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $state['users'] as $user ) { wp_delete_user( $user['id'] ); }
foreach ( $state['options'] as $name => $value ) { null === $value ? delete_option( $name ) : update_option( $name, $value ); }
unlink( $file ); echo "Browser fixtures removed and original settings restored.\n";
