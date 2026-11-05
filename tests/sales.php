<?php
/** Real WooCommerce integration tests; disposable database only. */
ob_start();
$site = $argv[1] ?? '';
if ( ! is_file( $site . '/wp-load.php' ) ) { throw new RuntimeException( 'Provide a disposable WordPress path.' ); }
require $site . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ! defined( 'UPOS_INTEGRATION_TESTS' ) || ! UPOS_INTEGRATION_TESTS ) { throw new RuntimeException( 'Disposable local test installation required.' ); }

use StarZarei\UniversalPOS\Database\Schema;
use StarZarei\UniversalPOS\Payments\Money;
use Automattic\WooCommerce\Checkout\Helpers\ReserveStock;

$checks = 0;
$email_events = 0; $email_inside_transaction = false; $transaction_open = false;
add_filter( 'query', static function ( $sql ) use ( &$transaction_open ) {
	if ( 'START TRANSACTION' === $sql ) { $transaction_open = true; }
	if ( in_array( $sql, array( 'COMMIT', 'ROLLBACK' ), true ) ) { $transaction_open = false; }
	return $sql;
} );
add_filter( 'pre_wp_mail', '__return_true' ); // Do not deliver real email from a disposable test.
add_filter( 'woocommerce_email_content_type', static fn() => 'text/plain' );
add_action( 'woocommerce_order_status_pending_to_processing_notification', static function () use ( &$email_events, &$email_inside_transaction, &$transaction_open ) {
	++$email_events;
	$email_inside_transaction = $email_inside_transaction || $transaction_open;
}, 1 );
function verify( bool $value, string $label ): void {
	global $checks;
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$checks;
	echo "PASS: $label\n";
}
function call_sale( string $method, string $route, ?array $body = null, string $key = 'integration-default-key' ): WP_REST_Response {
	$r = new WP_REST_Request( $method, '/universal-pos/v1' . $route );
	$r->set_header( 'Idempotency-Key', $key );
	if ( null !== $body ) { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); }
	return rest_get_server()->dispatch( $r );
}
function sale_body( int $id, int $qty = 1 ): array {
	return array( 'items' => array( array( 'product_id' => $id, 'quantity' => $qty ) ), 'billing' => array( 'first_name' => 'Test', 'last_name' => 'Cash Customer', 'phone' => '۰۹۱۲ ۳۴۵ ۶۷۸۹', 'country' => 'US', 'state' => 'CA', 'city' => 'Test' ) );
}
function pay_body( string $total, array $lines = array() ): array {
	return array( 'received' => true, 'expected_total' => $total, 'payments' => $lines ?: array( array( 'method' => 'cash', 'amount' => $total ) ) );
}
function row_count( string $table, int $id ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id = %d', Schema::table( $table ), $id ) );
}
$originals = array();
foreach ( array( 'woocommerce_custom_orders_table_enabled', 'woocommerce_custom_orders_table_data_sync_enabled', 'woocommerce_manage_stock', 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_based_on', 'woocommerce_hold_stock_minutes', 'upos_manual_payment_methods', 'woocommerce_default_country', 'woocommerce_price_num_decimals' ) as $name ) { $originals[ $name ] = get_option( $name, null ); }
$users = array(); $products = array(); $orders = array(); $rate_id = 0;
$admin = get_user_by( 'login', 'upos_test_admin' );
if ( ! $admin ) { throw new RuntimeException( 'Missing disposable administrator.' ); }
try {
	wp_set_current_user( $admin->ID );
	update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
	update_option( 'woocommerce_manage_stock', 'yes' );
	update_option( 'woocommerce_calc_taxes', 'no' );
	update_option( 'woocommerce_prices_include_tax', 'no' );
	update_option( 'woocommerce_hold_stock_minutes', '0' ); // POS must still reserve stock.
	update_option( 'upos_manual_payment_methods', array( 'cash', 'card_terminal', 'bank_transfer' ) );
	update_option( 'woocommerce_price_num_decimals', '2' );
	foreach ( array( 'cashier', 'other' ) as $suffix ) {
		$id = wp_insert_user( array( 'user_login' => 'upos_sale_' . $suffix . '_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'upos_cashier' ) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
		$users[] = $id;
	}
	wp_set_current_user( 0 );
	verify( 401 === call_sale( 'POST', '/sales', array() )->get_status(), 'anonymous sale creation rejected' );
	wp_set_current_user( $users[0] );
	verify( 400 === call_sale( 'POST', '/sales', array( 'items' => array() ), '' )->get_status(), 'missing idempotency header rejected' );
	verify( 400 === call_sale( 'POST', '/sales', array( 'items' => array(), 'total' => '0' ) )->get_status(), 'client-supplied price/total fields rejected' );
	verify( 200 === call_sale( 'GET', '/payment-methods' )->get_status(), 'cashier can discover manual payment methods' );
	verify( 30 === Money::minor( '0.30' ), 'money comparison uses exact minor units' );
	foreach ( array( 'no', 'yes' ) as $hpos ) {
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos );
		$p = new WC_Product_Simple();
		$p->set_name( 'POS sale fixture' ); $p->set_status( 'publish' ); $p->set_regular_price( '10.10' ); $p->set_manage_stock( true ); $p->set_stock_quantity( 10 );
		$products[] = $p->save();
		$body = sale_body( $p->get_id(), 2 );
		$key = 'prepare-' . $hpos . '-' . wp_generate_uuid4();
		$prepared = call_sale( 'POST', '/sales', $body, $key );
		verify( 200 === $prepared->get_status(), 'sale prepared with HPOS ' . $hpos . ': ' . wp_json_encode( $prepared->get_data() ) );
		$sale = $prepared->get_data(); $id = $sale['id']; $orders[] = $id;
		verify( (bool) as_next_scheduled_action( 'upos_expire_sale', array( 'order_id' => $id ), 'universal-pos' ), 'unpaid sale expiry is scheduled' );
		verify( '20.20' === $sale['total'] && 'pending' === $sale['status'] && ! $sale['paid'], "server prices pending order (HPOS $hpos)" );
		verify( '09123456789' === wc_get_order( $id )->get_billing_phone() && 0 === wc_get_order( $id )->get_customer_id(), 'guest phone normalized without creating account' );
		verify( 2 === ( new ReserveStock() )->get_reserved_stock( wc_get_product( $p->get_id() ) ), 'stock held even when global hold duration is zero' );
		verify( 10 === wc_get_product( $p->get_id() )->get_stock_quantity(), 'preparation reserves without reducing stock' );
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->insert( Schema::table( 'audit_logs' ), array( 'action' => 'nested_probe', 'object_type' => 'test', 'created_at' => current_time( 'mysql', true ) ) );
		$probe_id = $wpdb->insert_id;
		$suppressed = $wpdb->suppress_errors();
		try { $nested = call_sale( 'POST', '/sales', $body, 'nested-' . wp_generate_uuid4() ); }
		finally { $wpdb->suppress_errors( $suppressed ); $wpdb->query( 'ROLLBACK' ); }
		verify( 503 === $nested->get_status(), 'nested POS transaction rejected' );
		verify( null === $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', Schema::table( 'audit_logs' ), $probe_id ) ), 'rejecting nested transaction never commits caller data' );
		verify( $id === call_sale( 'POST', '/sales', $body, $key )->get_data()['id'], 'prepare retry returns same order' );
		$changed = sale_body( $p->get_id(), 3 );
		verify( 409 === call_sale( 'POST', '/sales', $changed, $key )->get_status(), 'same key with changed basket conflicts' );
		wp_set_current_user( $users[1] );
		verify( 404 === call_sale( 'GET', '/sales/' . $id )->get_status(), 'another cashier cannot read this sale' );
		verify( 404 === call_sale( 'POST', '/sales/' . $id . '/payments', pay_body( $sale['total'] ) )->get_status(), 'another cashier cannot pay this sale' );
		wp_set_current_user( $users[0] );
		$unconfirmed = pay_body( $sale['total'] ); $unconfirmed['received'] = false;
		verify( 400 === call_sale( 'POST', '/sales/' . $id . '/payments', $unconfirmed )->get_status(), 'manual receipt requires explicit confirmation' );
		$assign = $body; $assign['customer_id'] = $admin->ID;
		verify( 403 === call_sale( 'POST', '/sales', $assign, 'unauthorized-customer-' . wp_generate_uuid4() )->get_status(), 'cashier cannot assign existing customers without CRM permission' );
		$overprecise = pay_body( $sale['total'], array( array( 'method' => 'cash', 'amount' => '20.200' ) ) );
		verify( 400 === call_sale( 'POST', '/sales/' . $id . '/payments', $overprecise )->get_status(), 'payment cannot use unsupported decimal precision' );
		$mismatch = pay_body( $sale['total'], array( array( 'method' => 'cash', 'amount' => '1.00' ) ) );
		verify( 409 === call_sale( 'POST', '/sales/' . $id . '/payments', $mismatch )->get_status(), 'partial/unbalanced payment rejected' );
		verify( 0 === row_count( 'payment_lines', $id ) && 0 === row_count( 'financial_transactions', $id ), 'rejected payments leave no financial rows' );
		verify( 400 === call_sale( 'POST', '/sales/' . $id . '/payments', pay_body( $sale['total'], array( array( 'method' => 'card_terminal', 'amount' => $sale['total'] ) ) ) )->get_status(), 'terminal reference required' );
		$payment = pay_body( $sale['total'], array( array( 'method' => 'cash', 'amount' => '10.00' ), array( 'method' => 'card_terminal', 'amount' => '10.20', 'reference' => 'terminal-test-ref' ) ) );
		$payment_key = 'payment-' . wp_generate_uuid4();
		update_option( 'upos_manual_payment_methods', array( 'cash' ) );
		verify( 400 === call_sale( 'POST', '/sales/' . $id . '/payments', $payment, $payment_key )->get_status(), 'disabled manual payment method is rejected server-side' );
		update_option( 'upos_manual_payment_methods', array( 'cash', 'card_terminal', 'bank_transfer' ) );
		$paid = call_sale( 'POST', '/sales/' . $id . '/payments', $payment, $payment_key );
		verify( 200 === $paid->get_status(), 'split payment completed: ' . wp_json_encode( $paid->get_data() ) );
		verify( $paid->get_data()['paid'] && wc_get_order( $id )->get_date_paid() && 'pos' === $paid->get_data()['channel'], 'WooCommerce records paid order and POS channel' );
		verify( 8 === wc_get_product( $p->get_id() )->get_stock_quantity(), 'payment reduces shared WooCommerce stock once' );
		verify( 2 === row_count( 'payment_lines', $id ) && 2 === row_count( 'financial_transactions', $id ), 'split payment lines reconcile with financial ledger' );
		verify( 1 === row_count( 'stock_movements', $id ) && 2 === row_count( 'audit_logs', $id ), 'stock and sensitive operations audited' );
		verify( 0 === ( new ReserveStock() )->get_reserved_stock( wc_get_product( $p->get_id() ) ), 'payment releases stock hold' );
		verify( 200 === call_sale( 'POST', '/sales/' . $id . '/payments', $payment, $payment_key )->get_status(), 'payment retry succeeds idempotently' );
		verify( 8 === wc_get_product( $p->get_id() )->get_stock_quantity() && 2 === row_count( 'payment_lines', $id ) && 1 === row_count( 'stock_movements', $id ), 'retry duplicates neither money nor stock' );
		verify( 409 === call_sale( 'POST', '/sales/' . $id . '/payments', $payment, 'different-payment-key' )->get_status(), 'new payment key cannot pay completed sale again' );
		verify( 409 === call_sale( 'POST', '/sales/' . $id . '/payments', pay_body( $sale['total'] ), $payment_key )->get_status(), 'same payment key with changed split conflicts' );
		verify( 409 === call_sale( 'POST', '/sales/' . $id . '/cancel', array() )->get_status(), 'paid sale requires refund instead of cancellation' );
		wc_get_order( $id )->payment_complete();
		verify( 8 === wc_get_product( $p->get_id() )->get_stock_quantity(), 'duplicate WooCommerce payment callback does not reduce twice' );

		$cancel_response = call_sale( 'POST', '/sales', sale_body( $p->get_id() ), 'cancel-' . wp_generate_uuid4() );
		verify( 200 === $cancel_response->get_status(), 'cancellation fixture prepares: ' . wp_json_encode( $cancel_response->get_data() ) );
		$cancelled = $cancel_response->get_data(); $cancel_id = $cancelled['id']; $orders[] = $cancel_id;
		verify( 'cancelled' === call_sale( 'POST', '/sales/' . $cancel_id . '/cancel', array() )->get_data()['status'], 'unpaid sale can be cancelled' );
		verify( 200 === call_sale( 'POST', '/sales/' . $cancel_id . '/cancel', array() )->get_status(), 'cancellation retry is idempotent' );
		verify( 0 === ( new ReserveStock() )->get_reserved_stock( wc_get_product( $p->get_id() ) ) && 8 === wc_get_product( $p->get_id() )->get_stock_quantity(), 'cancel releases hold without changing stock' );
		verify( 409 === call_sale( 'POST', '/sales/' . $cancel_id . '/payments', pay_body( $cancelled['total'] ) )->get_status(), 'cancelled sale cannot receive payment' );

		$expire = call_sale( 'POST', '/sales', sale_body( $p->get_id() ), 'expire-' . wp_generate_uuid4() )->get_data(); $orders[] = $expire['id'];
		$order = wc_get_order( $expire['id'] ); $order->update_meta_data( '_upos_expires_at', time() - 1 ); $order->save();
		verify( 409 === call_sale( 'POST', '/sales/' . $expire['id'] . '/payments', pay_body( $expire['total'] ) )->get_status(), 'expired sale cannot receive payment' );
		do_action( 'upos_expire_sale', $expire['id'] );
		verify( 'cancelled' === wc_get_order( $expire['id'] )->get_status(), 'scheduled expiry cancels unpaid order' );

		// A reservation by an online WooCommerce order competes with POS stock.
		$online = wc_create_order(); $orders[] = $online->get_id(); $online->add_product( wc_get_product( $p->get_id() ), 8 ); $online->calculate_totals();
		( new ReserveStock() )->reserve_stock_for_order( $online, 5 );
		$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'sale_requests' ) ) );
		$unavailable = call_sale( 'POST', '/sales', sale_body( $p->get_id() ), 'online-conflict-' . wp_generate_uuid4() );
		verify( 409 === $unavailable->get_status(), 'online reservation prevents overselling in POS' );
		verify( $before === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'sale_requests' ) ) ), 'failed preparation leaves no request registry row' );
		( new ReserveStock() )->release_stock_for_order( $online );
		$online->delete( true ); array_pop( $orders );

		// Failure after stock mutation must roll back order, payment, ledger and stock.
		$failure = call_sale( 'POST', '/sales', sale_body( $p->get_id() ), 'rollback-' . wp_generate_uuid4() )->get_data(); $orders[] = $failure['id'];
		$fail_key = 'failed-payment-' . wp_generate_uuid4();
		$throw = static function () { throw new RuntimeException( 'Injected audit persistence failure.' ); };
		add_action( 'woocommerce_payment_complete', $throw, 999 );
		$before_email_events = $email_events;
		try { $failed = call_sale( 'POST', '/sales/' . $failure['id'] . '/payments', pay_body( $failure['total'] ), $fail_key ); }
		finally { remove_action( 'woocommerce_payment_complete', $throw, 999 ); }
		verify( 503 === $failed->get_status(), 'injected payment-completion failure reported' );
		verify( $before_email_events === $email_events, 'rollback sends no transactional email notification' );
		verify( 8 === wc_get_product( $p->get_id() )->get_stock_quantity() && ! wc_get_order( $failure['id'] )->is_paid(), 'payment failure rolls back stock and paid state' );
		verify( 0 === row_count( 'payment_lines', $failure['id'] ) && 0 === row_count( 'financial_transactions', $failure['id'] ), 'payment failure rolls back financial writes' );
		$retry = call_sale( 'POST', '/sales/' . $failure['id'] . '/payments', pay_body( $failure['total'] ), $fail_key );
		verify( 200 === $retry->get_status() && $retry->get_data()['paid'], 'same key can safely retry after rollback' );
		verify( $email_events > $before_email_events && ! $email_inside_transaction, 'successful commit sends email notifications only after transaction ends' );

		// Backorders permitted online still require available physical stock in POS.
		wc_update_product_stock( $p->get_id(), 1, 'set' );
		$fresh = wc_get_product( $p->get_id() ); $fresh->set_backorders( 'yes' ); $fresh->save();
		$last = call_sale( 'POST', '/sales', sale_body( $p->get_id() ), 'last-' . wp_generate_uuid4() )->get_data(); $orders[] = $last['id'];
		verify( 409 === call_sale( 'POST', '/sales', sale_body( $p->get_id() ), 'last-conflict-' . wp_generate_uuid4() )->get_status(), 'last unit reserved once even with online backorders enabled' );
		$last_paid = call_sale( 'POST', '/sales/' . $last['id'] . '/payments', pay_body( $last['total'], array( array( 'method' => 'bank_transfer', 'amount' => $last['total'], 'reference' => 'bank-test-ref' ) ) ), 'last-payment-' . wp_generate_uuid4() );
		verify( 200 === $last_paid->get_status() && 0 === wc_get_product( $p->get_id() )->get_stock_quantity(), 'bank payment sells final unit without negative stock' );

		$free = new WC_Product_Simple(); $free->set_name( 'Free sale fixture' ); $free->set_regular_price( '0' ); $products[] = $free->save();
		$free_sale = call_sale( 'POST', '/sales', sale_body( $free->get_id() ), 'free-' . wp_generate_uuid4() )->get_data(); $orders[] = $free_sale['id'];
		$free_paid = call_sale( 'POST', '/sales/' . $free_sale['id'] . '/payments', array( 'received' => true, 'expected_total' => '0.00', 'payments' => array() ), 'free-payment-' . wp_generate_uuid4() );
		verify( 200 === $free_paid->get_status() && $free_paid->get_data()['paid'] && 0 === row_count( 'financial_transactions', $free_sale['id'] ), 'zero-total order completes without fabricating a money receipt' );

		// Fully specified variations can share their parent's stock.
		$parent = new WC_Product_Variable(); $parent->set_name( 'Parent stock fixture' ); $parent->set_manage_stock( true ); $parent->set_stock_quantity( 2 );
		$attr = new WC_Product_Attribute(); $attr->set_name( 'size' ); $attr->set_options( array( 'large', 'small' ) ); $attr->set_variation( true ); $parent->set_attributes( array( $attr ) );
		$products[] = $parent->save();
		$variation = new WC_Product_Variation(); $variation->set_parent_id( $parent->get_id() ); $variation->set_regular_price( '7.00' ); $variation->set_attributes( array( 'size' => 'large' ) ); $products[] = $variation->save();
		WC_Product_Variable::sync( $parent->get_id() );
		$variable = call_sale( 'POST', '/sales', sale_body( $variation->get_id() ), 'variant-' . wp_generate_uuid4() );
		verify( 200 === $variable->get_status(), 'specific variation can be prepared: ' . wp_json_encode( $variable->get_data() ) );
		$variable = $variable->get_data(); $orders[] = $variable['id'];
		$variable_pay = call_sale( 'POST', '/sales/' . $variable['id'] . '/payments', pay_body( $variable['total'] ), 'variant-payment-' . wp_generate_uuid4() );
		verify( 200 === $variable_pay->get_status() && 1 === wc_get_product( $parent->get_id() )->get_stock_quantity(), 'variation sale reduces shared parent stock' );
		verify( $variation->get_id() === $variable_pay->get_data()['items'][0]['variation_id'], 'WooCommerce order preserves selected variation identity' );

		// Use a dedicated tax class, so pre-existing rates cannot influence this test.
		wp_set_current_user( $admin->ID );
		$rate_id = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'US', 'tax_rate_state' => 'CA', 'tax_rate' => '10.0000', 'tax_rate_name' => 'POS integration tax', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => 'upos-integration-tax' ) );
		$taxed_product = new WC_Product_Simple(); $taxed_product->set_name( 'Tax fixture' ); $taxed_product->set_regular_price( '100.00' ); $taxed_product->set_tax_status( 'taxable' );
		add_filter( 'woocommerce_tax_classes', $tax_class_filter = static fn( $classes ) => array_merge( $classes, array( 'upos-integration-tax' ) ) );
		$taxed_product->set_tax_class( 'upos-integration-tax' ); $products[] = $taxed_product->save();
		update_option( 'woocommerce_calc_taxes', 'yes' ); update_option( 'woocommerce_tax_based_on', 'billing' ); update_option( 'woocommerce_default_country', 'US:CA' );
		wp_set_current_user( $users[0] );
		$tax_sale = call_sale( 'POST', '/sales', sale_body( $taxed_product->get_id() ), 'tax-exclusive-' . wp_generate_uuid4() );
		verify( 200 === $tax_sale->get_status(), 'tax-exclusive order prepared: ' . wp_json_encode( $tax_sale->get_data() ) );
		$tax_sale = $tax_sale->get_data(); $orders[] = $tax_sale['id'];
		verify( '110.00' === $tax_sale['total'] && 10.0 === (float) $tax_sale['tax'], 'WooCommerce tax-exclusive price produces tax-inclusive amount due' );
		update_option( 'woocommerce_price_num_decimals', '0' );
		verify( 409 === call_sale( 'POST', '/sales/' . $tax_sale['id'] . '/payments', pay_body( '110' ) )->get_status(), 'currency precision change requires reviewing the quote' );
		update_option( 'woocommerce_price_num_decimals', '2' );
		call_sale( 'POST', '/sales/' . $tax_sale['id'] . '/cancel', array() );
		update_option( 'woocommerce_prices_include_tax', 'yes' );
		$taxed_product->set_regular_price( '110.00' ); $taxed_product->save();
		$tax_inclusive = call_sale( 'POST', '/sales', sale_body( $taxed_product->get_id() ), 'tax-inclusive-' . wp_generate_uuid4() );
		verify( 200 === $tax_inclusive->get_status(), 'tax-inclusive order prepared: ' . wp_json_encode( $tax_inclusive->get_data() ) );
		$tax_inclusive = $tax_inclusive->get_data(); $orders[] = $tax_inclusive['id'];
		verify( '110.00' === $tax_inclusive['total'] && 10.0 === (float) $tax_inclusive['tax'], 'WooCommerce tax-inclusive price is not taxed twice' );
		call_sale( 'POST', '/sales/' . $tax_inclusive['id'] . '/cancel', array() );
		WC_Tax::_delete_tax_rate( $rate_id ); $rate_id = 0;
		remove_filter( 'woocommerce_tax_classes', $tax_class_filter );
		update_option( 'woocommerce_calc_taxes', 'no' ); update_option( 'woocommerce_prices_include_tax', 'no' );

		// Remove this iteration's orders while its data store is still selected.
		foreach ( $orders as $order_id ) {
			as_unschedule_all_actions( 'upos_expire_sale', array( 'order_id' => $order_id ), 'universal-pos' );
			$order = wc_get_order( $order_id ); if ( $order ) { ( new ReserveStock() )->release_stock_for_order( $order ); $order->delete( true ); }
			foreach ( array( 'sale_requests', 'payment_lines', 'financial_transactions', 'stock_movements', 'audit_logs' ) as $table ) { $wpdb->delete( Schema::table( $table ), array( 'order_id' => $order_id ) ); }
		}
		$orders = array();
	}
	echo "Completed $checks sales checks.\n";
} finally {
	foreach ( $orders as $id ) {
		as_unschedule_all_actions( 'upos_expire_sale', array( 'order_id' => $id ), 'universal-pos' );
		$order = wc_get_order( $id ); if ( $order ) { ( new ReserveStock() )->release_stock_for_order( $order ); $order->delete( true ); }
		foreach ( array( 'sale_requests', 'payment_lines', 'financial_transactions', 'stock_movements', 'audit_logs' ) as $table ) { $wpdb->delete( Schema::table( $table ), array( 'order_id' => $id ) ); }
	}
	foreach ( $products as $id ) { $product = wc_get_product( $id ); if ( $product ) { $product->delete( true ); } }
	if ( $rate_id ) { WC_Tax::_delete_tax_rate( $rate_id ); }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $id ) { wp_delete_user( $id ); }
	foreach ( $originals as $name => $value ) { null === $value ? delete_option( $name ) : update_option( $name, $value ); }
}
