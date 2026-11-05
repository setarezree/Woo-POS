<?php
/** Phase three against a real disposable WooCommerce store, both HPOS modes. */
ob_start(); $site = $argv[1] ?? ''; require $site . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ! defined( 'UPOS_INTEGRATION_TESTS' ) || ! UPOS_INTEGRATION_TESTS ) { throw new RuntimeException( 'Disposable local site required.' ); }
use StarZarei\UniversalPOS\Database\Schema;
use Automattic\WooCommerce\Checkout\Helpers\ReserveStock;
add_filter( 'pre_wp_mail', '__return_true' ); add_filter( 'woocommerce_email_content_type', static fn() => 'text/plain' );
$checks = 0; $orders = array(); $products = array(); $users = array(); $rates = array(); $created_class = false;
function mg_check( bool $ok, string $label ): void { global $checks; if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$checks; echo "PASS: $label\n"; }
function mg_call( string $method, string $route, array $body = array(), ?string $key = null ): WP_REST_Response {
 $r = new WP_REST_Request( $method, '/universal-pos/v1/management' . $route );
 if ( 'GET' === $method ) { $r->set_query_params( $body ); } else { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); }
 $r->set_header( 'Idempotency-Key', $key ?? 'mg-' . wp_generate_uuid4() ); return rest_get_server()->dispatch( $r );
}
function mg_ok( WP_REST_Response $r ): array { if ( 200 !== $r->get_status() ) { throw new RuntimeException( 'Unexpected response: ' . wp_json_encode( $r->get_data() ) ); } return $r->get_data(); }
function mg_product( string $price = '10.10', int $stock = 20 ): WC_Product_Simple { global $products; $p = new WC_Product_Simple(); $p->set_name( 'Management fixture ' . wp_generate_uuid4() ); $p->set_regular_price( $price ); $p->set_manage_stock( true ); $p->set_stock_quantity( $stock ); $products[] = $p->save(); return $p; }
function mg_order( WC_Product $p, int $qty = 3, bool $paid = true ): WC_Order {
 global $orders; $o = wc_create_order(); $orders[] = $o->get_id(); $o->set_billing_first_name( 'InventoryCustomer' ); $o->set_billing_last_name( 'ReturnTest' ); $o->set_billing_phone( '09121112233' ); $o->add_product( $p, $qty ); $o->calculate_totals(); $o->save(); if ( $paid ) { $o->payment_complete(); } return wc_get_order( $o->get_id() );
}
function mg_return( WC_Order $o, int $qty, string $condition, string $mode = 'partial' ): array { return array( 'mode' => $mode, 'items' => array( array( 'item_id' => (int) array_key_first( $o->get_items() ), 'quantity' => $qty, 'condition' => $condition ) ), 'reason' => 'Test actual returned goods' ); }
function mg_commit( int $id, array $base, array $quote, ?string $key = null ): WP_REST_Response { return mg_call( 'POST', '/orders/' . $id . '/refunds', array_merge( $base, array( 'quote_hash' => $quote['quote_hash'], 'expected_amount' => $quote['amount'], 'received' => true, 'method' => 'cash', 'reference' => '' ) ), $key ); }
$admin = get_user_by( 'login', 'upos_test_admin' ); if ( ! $admin ) { throw new RuntimeException( 'Missing administrator.' ); }
$original = array(); foreach ( array( 'woocommerce_custom_orders_table_enabled', 'woocommerce_custom_orders_table_data_sync_enabled', 'woocommerce_manage_stock', 'woocommerce_calc_taxes', 'woocommerce_price_num_decimals', 'upos_manual_payment_methods', 'woocommerce_prices_include_tax', 'woocommerce_tax_based_on' ) as $option ) { $original[ $option ] = get_option( $option, null ); }
try {
 wp_set_current_user( $admin->ID ); update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' ); update_option( 'woocommerce_manage_stock', 'yes' ); update_option( 'woocommerce_calc_taxes', 'no' ); update_option( 'woocommerce_price_num_decimals', '2' ); update_option( 'upos_manual_payment_methods', array( 'cash', 'card_terminal', 'bank_transfer' ) );
 if ( ! in_array( 'upos-mg-tax', WC_Tax::get_tax_class_slugs(), true ) ) { WC_Tax::create_tax_class( 'UPOS MG Tax', 'upos-mg-tax' ); $created_class = true; }
 $cashier = wp_insert_user( array( 'user_login' => 'mg-cashier-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 40 ), 'role' => 'upos_cashier' ) ); $users[] = $cashier;
 wp_set_current_user( 0 ); mg_check( 401 === mg_call( 'GET', '/orders' )->get_status(), 'anonymous manager access rejected' );
 wp_set_current_user( $cashier ); mg_check( 403 === mg_call( 'GET', '/products' )->get_status(), 'ordinary cashier cannot read private management inventory' ); mg_check( 403 === mg_call( 'GET', '/orders' )->get_status(), 'ordinary cashier cannot read all customers orders' );
 wp_set_current_user( $admin->ID );
 foreach ( array( 'no', 'yes' ) as $hpos ) {
  update_option( 'woocommerce_custom_orders_table_enabled', $hpos );
  $p = mg_product(); $id = $p->get_id();
  $opening = mg_ok( mg_call( 'GET', '/stock-history', array( 'product_id' => $id ) ) )['movements']; mg_check( 1 === count( $opening ) && '20' === (string) (float) $opening[0]['quantity_after'], 'native product creation records one opening balance' );
  $p->set_stock_quantity( 22 ); $p->save();
  $native = mg_ok( mg_call( 'GET', '/stock-history', array( 'product_id' => $id ) ) )['movements']; mg_check( 2 === count( $native ) && 2.0 === (float) $native[0]['quantity_change'], 'native product edit logs actual before/after once' );
  $key = 'receipt-' . wp_generate_uuid4(); $body = array( 'type' => 'receipt', 'quantity' => 5, 'expected_stock' => 22, 'reason' => 'Received goods', 'low_stock_amount' => 4 );
  $adjusted = mg_ok( mg_call( 'POST', '/products/' . $id . '/stock', $body, $key ) ); mg_check( 27 === $adjusted['physical_stock'] && 4 === $adjusted['low_stock_amount'], 'stock receipt and native minimum threshold are saved' );
  mg_ok( mg_call( 'POST', '/products/' . $id . '/stock', $body, $key ) ); mg_check( 27 === wc_get_product( $id )->get_stock_quantity(), 'same stock request cannot add goods twice' );
  $changed = $body; $changed['quantity'] = 6; mg_check( 409 === mg_call( 'POST', '/products/' . $id . '/stock', $changed, $key )->get_status(), 'changed stock body with same key rejected' );
  mg_check( 409 === mg_call( 'POST', '/products/' . $id . '/stock', $body )->get_status(), 'stale stock count rejected' );
  $damage = array( 'type' => 'damage', 'quantity' => 2, 'expected_stock' => 27, 'reason' => 'Damaged stock' ); mg_ok( mg_call( 'POST', '/products/' . $id . '/stock', $damage ) ); mg_check( 25 === wc_get_product( $id )->get_stock_quantity(), 'damage subtracts physical stock' );
  $held = mg_order( wc_get_product( $id ), 24, false ); ( new ReserveStock() )->reserve_stock_for_order( $held, 5 );
  $damage['expected_stock'] = 25; mg_check( 409 === mg_call( 'POST', '/products/' . $id . '/stock', $damage )->get_status(), 'adjustment cannot consume reserved goods' ); ( new ReserveStock() )->release_stock_for_order( $held );
  $order = mg_order( wc_get_product( $id ) ); $oid = $order->get_id();
  $history = mg_ok( mg_call( 'GET', '/stock-history', array( 'order_id' => $oid ) ) )['movements']; mg_check( 1 === count( $history ) && 'online_sale' === $history[0]['type'] && -3.0 === (float) $history[0]['quantity_change'], 'online checkout decorates one stock transition with its real order' );
  $search = mg_ok( mg_call( 'GET', '/orders', array( 'search' => '09121112233', 'channel' => 'online' ) ) ); mg_check( in_array( $oid, array_column( $search['orders'], 'id' ), true ), 'native order search supports customer phone and online channel' );
  // Native metadata CRUD simulates a legacy missing channel without disabling save hooks.
  $order->delete_meta_data( '_upos_sales_channel' ); $order->save_meta_data();
  mg_check( in_array( $oid, array_column( mg_ok( mg_call( 'GET', '/orders', array( 'channel' => 'online' ) ) )['orders'], 'id' ), true ), 'legacy orders without channel metadata remain online in both stores' );
  mg_check( ! in_array( $oid, array_column( mg_ok( mg_call( 'GET', '/orders', array( 'channel' => 'pos' ) ) )['orders'], 'id' ), true ), 'POS channel filter excludes online and legacy orders' );
  $note_key = 'note-' . wp_generate_uuid4(); $before_notes = count( wc_get_order_notes( array( 'order_id' => $oid, 'limit' => 100 ) ) );
  mg_ok( mg_call( 'POST', '/orders/' . $oid . '/notes', array( 'note' => 'Return reviewed internally' ), $note_key ) ); mg_ok( mg_call( 'POST', '/orders/' . $oid . '/notes', array( 'note' => 'Return reviewed internally' ), $note_key ) );
  mg_check( $before_notes + 1 === count( wc_get_order_notes( array( 'order_id' => $oid, 'limit' => 100 ) ) ), 'internal note retry adds only one actual note' );
  mg_check( 409 === mg_call( 'POST', '/orders/' . $oid . '/status', array( 'status' => 'cancelled', 'expected_status' => 'processing' ) )->get_status(), 'paid order requires refund rather than cancellation' );
  mg_check( 409 === mg_call( 'POST', '/orders/' . $held->get_id() . '/status', array( 'status' => 'completed', 'expected_status' => 'pending' ) )->get_status(), 'status management cannot invent payment' );
  update_option( 'woocommerce_price_num_decimals', '0' );
  mg_check( 409 === mg_call( 'POST', '/orders/' . $oid . '/refund-quote', mg_return( $order, 1, 'saleable' ) )->get_status(), 'online order remembers original currency precision and rejects a later incompatible setting' ); update_option( 'woocommerce_price_num_decimals', '2' );
  $base = mg_return( $order, 1, 'saleable' ); $quote = mg_ok( mg_call( 'POST', '/orders/' . $oid . '/refund-quote', $base ) ); mg_check( '10.10' === $quote['amount'], 'partial refund is calculated from saved discounted order lines' );
  $unconfirmed = array_merge( $base, array( 'quote_hash' => $quote['quote_hash'], 'expected_amount' => $quote['amount'], 'received' => false, 'method' => 'cash' ) ); mg_check( 400 === mg_call( 'POST', '/orders/' . $oid . '/refunds', $unconfirmed )->get_status(), 'refund money requires explicit returned-funds confirmation' );
  $stock_before = wc_get_product( $id )->get_stock_quantity(); $refund_key = 'refund-' . wp_generate_uuid4(); $refund = mg_ok( mg_commit( $oid, $base, $quote, $refund_key ) );
  mg_check( wc_get_order( $refund['refund_id'] ) instanceof WC_Order_Refund && 1 === count( wc_get_order( $oid )->get_refunds() ), 'partial return creates native WooCommerce refund' );
  mg_check( $stock_before + 1 === wc_get_product( $id )->get_stock_quantity(), 'saleable return restocks once using native WooCommerce' );
  mg_ok( mg_commit( $oid, $base, $quote, $refund_key ) ); mg_check( 1 === count( wc_get_order( $oid )->get_refunds() ) && $stock_before + 1 === wc_get_product( $id )->get_stock_quantity(), 'refund retry duplicates neither goods nor money' );
  mg_check( 409 === mg_commit( $oid, $base, $quote )->get_status(), 'stale quote cannot create a second return' );
  $lookup = mg_ok( mg_call( 'GET', '/operations', array( 'kind' => 'refund:' . $oid, 'key' => $refund_key ) ) ); mg_check( $lookup['refund_id'] === $refund['refund_id'], 'uncertain refund response recovers only native result IDs' );
  $remaining = mg_return( wc_get_order( $oid ), 2, 'damaged', 'full' ); $full_quote = mg_ok( mg_call( 'POST', '/orders/' . $oid . '/refund-quote', $remaining ) ); $full = mg_ok( mg_commit( $oid, $remaining, $full_quote ) );
  mg_check( 'refunded' === wc_get_order( $oid )->get_status() && '30.30' === wc_format_decimal( wc_get_order( $oid )->get_total_refunded(), 2 ), 'remaining full refund reconciles the entire native order: ' . wc_get_order( $oid )->get_status() . ' / ' . wc_get_order( $oid )->get_total_refunded() );
  mg_check( $stock_before + 1 === wc_get_product( $id )->get_stock_quantity(), 'damaged goods never return to saleable stock' );
  $sum = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM %i WHERE order_id = %d AND type = 'sale_refund'", Schema::table( 'financial_transactions' ), $oid ) ); mg_check( 30.3 === (float) $sum, 'refund outflows reconcile without changing original receipts' );
  $abandon_key = 'abandon-' . wp_generate_uuid4(); mg_ok( mg_call( 'POST', '/operations/abandon', array( 'kind' => 'stock:' . $id, 'key' => $abandon_key ) ) );
  $damage['expected_stock'] = wc_get_product( $id )->get_stock_quantity(); mg_check( 409 === mg_call( 'POST', '/products/' . $id . '/stock', $damage, $abandon_key )->get_status(), 'abandon tombstone blocks delayed inventory mutation' );
  $rollback_order = mg_order( wc_get_product( $id ), 1 ); $rid = $rollback_order->get_id(); $rollback_body = mg_return( $rollback_order, 1, 'saleable', 'full' ); $rq = mg_ok( mg_call( 'POST', '/orders/' . $rid . '/refund-quote', $rollback_body ) ); $rs = wc_get_product( $id )->get_stock_quantity();
  $injected = static function ( $sql ) { if ( str_contains( $sql, 'INSERT INTO' ) && str_contains( $sql, 'upos_audit_logs' ) && str_contains( $sql, 'order_refunded' ) ) { throw new RuntimeException( 'Injected refund audit failure.' ); } return $sql; };
  add_filter( 'query', $injected, 999 ); $failed = mg_commit( $rid, $rollback_body, $rq, 'rollback-' . $hpos ); remove_filter( 'query', $injected, 999 );
  mg_check( 503 === $failed->get_status() && 0 === count( wc_get_order( $rid )->get_refunds() ) && $rs === wc_get_product( $id )->get_stock_quantity(), 'late failure rolls back native refund, restock and parent order' );
  mg_check( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE order_id = %d AND type = 'sale_refund'", Schema::table( 'financial_transactions' ), $rid ) ), 'rollback leaves no refund financial transaction' );
  mg_ok( mg_commit( $rid, $rollback_body, $rq, 'rollback-' . $hpos ) ); mg_check( 1 === count( wc_get_order( $rid )->get_refunds() ), 'same failed refund key can safely retry after rollback' );
  $removed_product = mg_product(); $removed_order = mg_order( $removed_product, 1 ); $removed_product->delete( true );
  $removed_body = mg_return( $removed_order, 1, 'saleable', 'full' );
  mg_check( 409 === mg_call( 'POST', '/orders/' . $removed_order->get_id() . '/refund-quote', $removed_body )->get_status(), 'deleted products cannot promise a saleable restock' );
  $removed_body['items'][0]['condition'] = 'inspection'; $removed_quote = mg_ok( mg_call( 'POST', '/orders/' . $removed_order->get_id() . '/refund-quote', $removed_body ) ); mg_ok( mg_commit( $removed_order->get_id(), $removed_body, $removed_quote ) );
  mg_check( 1 === count( wc_get_order( $removed_order->get_id() )->get_refunds() ), 'deleted product can be returned for inspection without recreating phantom stock' );
  $old_precision_order = mg_order( mg_product( '10.00' ), 1 ); $old_precision_order->delete_meta_data( '_upos_price_decimals' ); $old_precision_order->save_meta_data();
  wc_create_refund( array( 'order_id' => $old_precision_order->get_id(), 'amount' => '0.50', 'refund_payment' => false ) ); update_option( 'woocommerce_price_num_decimals', '0' );
  mg_check( 409 === mg_call( 'POST', '/orders/' . $old_precision_order->get_id() . '/refund-quote', mg_return( $old_precision_order, 1, 'inspection' ) )->get_status(), 'legacy nonrepresentable refund balance cannot round into excess returned money' ); update_option( 'woocommerce_price_num_decimals', '2' );
  $unpaid_status = mg_ok( mg_call( 'POST', '/orders/' . $held->get_id() . '/status', array( 'status' => 'cancelled', 'expected_status' => 'pending' ) ) ); mg_check( 'cancelled' === $unpaid_status['status'], 'unpaid native order can be cancelled without inventing refund money' );
  $inspect_order = mg_order( wc_get_product( $id ), 1 ); $ib = mg_return( $inspect_order, 1, 'inspection', 'full' ); $iq = mg_ok( mg_call( 'POST', '/orders/' . $inspect_order->get_id() . '/refund-quote', $ib ) ); $is = wc_get_product( $id )->get_stock_quantity();
  mg_ok( mg_commit( $inspect_order->get_id(), $ib, $iq ) ); mg_check( $is === wc_get_product( $id )->get_stock_quantity(), 'goods needing inspection are excluded from saleable stock' );
  $free = mg_product( '0.00' ); $free_order = mg_order( $free, 1 ); $fb = mg_return( $free_order, 1, 'saleable', 'full' ); $fq = mg_ok( mg_call( 'POST', '/orders/' . $free_order->get_id() . '/refund-quote', $fb ) ); mg_ok( mg_commit( $free_order->get_id(), $fb, $fq ) );
  mg_check( '0.00' === $fq['amount'] && 20 === wc_get_product( $free->get_id() )->get_stock_quantity(), 'zero-value return restores goods without fabricated refund money' );
  mg_check( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE order_id = %d", Schema::table( 'financial_transactions' ), $free_order->get_id() ) ), 'free return creates no financial outflow' );
  $parent = new WC_Product_Variable(); $parent->set_name( 'Management parent stock' ); $parent->set_manage_stock( true ); $parent->set_stock_quantity( 8 ); $attribute = new WC_Product_Attribute(); $attribute->set_name( 'size' ); $attribute->set_options( array( 'large' ) ); $attribute->set_variation( true ); $parent->set_attributes( array( $attribute ) ); $products[] = $parent->save();
  $v = new WC_Product_Variation(); $v->set_parent_id( $parent->get_id() ); $v->set_attributes( array( 'size' => 'large' ) ); $v->set_regular_price( '7.00' ); $products[] = $v->save(); WC_Product_Variable::sync( $parent->get_id() );
  $v_order = mg_order( wc_get_product( $v->get_id() ), 1 ); $vb = mg_return( $v_order, 1, 'saleable', 'full' ); $vq = mg_ok( mg_call( 'POST', '/orders/' . $v_order->get_id() . '/refund-quote', $vb ) ); mg_ok( mg_commit( $v_order->get_id(), $vb, $vq ) );
  mg_check( 8 === wc_get_product( $parent->get_id() )->get_stock_quantity(), 'variation return correctly restores shared parent stock' );
  $vh = mg_ok( mg_call( 'GET', '/stock-history', array( 'order_id' => $v_order->get_id() ) ) )['movements']; mg_check( 2 === count( $vh ) && $v->get_id() === (int) $vh[0]['variation_id'] && 7.0 === (float) $vh[0]['quantity_before'], 'variation ledger retains original child identity and actual parent before/after stock' );
  $va = mg_ok( mg_call( 'POST', '/products/' . $v->get_id() . '/stock', array( 'type' => 'receipt', 'quantity' => 2, 'expected_stock' => 8, 'reason' => 'Parent goods received' ) ) ); mg_check( 10 === $va['physical_stock'] && 10 === wc_get_product( $parent->get_id() )->get_stock_quantity(), 'variation inventory adjustment resolves its native stock owner' );
  update_option( 'woocommerce_calc_taxes', 'yes' ); update_option( 'woocommerce_prices_include_tax', 'no' ); update_option( 'woocommerce_tax_based_on', 'base' );
  $rates[] = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '10.0000', 'tax_rate_name' => 'Management test tax', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_class' => 'upos-mg-tax' ) );
  add_filter( 'woocommerce_tax_classes', $tax_filter = static fn( $classes ) => array_merge( $classes, array( 'upos-mg-tax' ) ) );
  $taxed = mg_product(); $taxed->set_tax_class( 'upos-mg-tax' ); $taxed->save(); $tax_order = mg_order( wc_get_product( $taxed->get_id() ), 3, false ); $tax_order->calculate_totals();
  $virtual = new WC_Coupon(); $virtual->set_code( 'mg-virtual-discount' ); $virtual->set_virtual( true ); $virtual->set_discount_type( 'percent' ); $virtual->set_amount( '10' ); $tax_order->apply_coupon( $virtual ); $tax_order->save(); $tax_order->payment_complete(); $tax_order = wc_get_order( $tax_order->get_id() );
  $tb = mg_return( $tax_order, 1, 'saleable' ); $tq = mg_ok( mg_call( 'POST', '/orders/' . $tax_order->get_id() . '/refund-quote', $tb ) );
  mg_check( '10.00' === $tq['amount'], 'partial refund preserves native discount and tax allocation with exact currency rounding' ); mg_ok( mg_commit( $tax_order->get_id(), $tb, $tq ) );
  $tb2 = mg_return( wc_get_order( $tax_order->get_id() ), 2, 'damaged', 'full' ); $tq2 = mg_ok( mg_call( 'POST', '/orders/' . $tax_order->get_id() . '/refund-quote', $tb2 ) ); mg_ok( mg_commit( $tax_order->get_id(), $tb2, $tq2 ) );
  mg_check( '20.00' === $tq2['amount'] && '30.00' === wc_format_decimal( wc_get_order( $tax_order->get_id() )->get_total_refunded(), 2 ), 'last return absorbs remaining rounding and reconciles all discounted tax' );
  $ship_order = mg_order( wc_get_product( $taxed->get_id() ), 1, false ); $ship = new WC_Order_Item_Shipping(); $ship->set_method_title( 'Shipping fixture' ); $ship->set_total( '5.00' ); $ship_order->add_item( $ship ); $fee = new WC_Order_Item_Fee(); $fee->set_name( 'Fee fixture' ); $fee->set_total( '2.00' ); $fee->set_tax_class( 'upos-mg-tax' ); $ship_order->add_item( $fee ); $ship_order->calculate_totals(); $ship_order->save(); $ship_order->payment_complete();
  $sb = mg_return( wc_get_order( $ship_order->get_id() ), 1, 'saleable', 'full' ); $sq = mg_ok( mg_call( 'POST', '/orders/' . $ship_order->get_id() . '/refund-quote', $sb ) );
  mg_check( wc_format_decimal( $ship_order->get_total(), 2 ) === $sq['amount'] && 3 === count( $sq['lines'] ), 'full online refund includes remaining native shipping and fee taxes' ); mg_ok( mg_commit( $ship_order->get_id(), $sb, $sq ) );
  remove_filter( 'woocommerce_tax_classes', $tax_filter ); update_option( 'woocommerce_calc_taxes', 'no' );
  $guard_order = mg_order( wc_get_product( $id ), 1 ); $gb = mg_return( $guard_order, 1, 'saleable', 'full' ); $gq = mg_ok( mg_call( 'POST', '/orders/' . $guard_order->get_id() . '/refund-quote', $gb ) ); $gs = wc_get_product( $id )->get_stock_quantity();
  $ignore_failure = static function () { global $wpdb; $suppressed = $wpdb->suppress_errors(); try { $wpdb->query( 'SELECT upos_nonexistent_column FROM ' . $wpdb->options ); } finally { $wpdb->suppress_errors( $suppressed ); } };
  add_action( 'woocommerce_create_refund', $ignore_failure ); $guarded = mg_commit( $guard_order->get_id(), $gb, $gq ); remove_action( 'woocommerce_create_refund', $ignore_failure );
  mg_check( 503 === $guarded->get_status() && 0 === count( wc_get_order( $guard_order->get_id() )->get_refunds() ) && $gs === wc_get_product( $id )->get_stock_quantity(), 'ignored native SQL failure cannot continue into refund or stock writes' );
  $nested_commit = static function () { global $wpdb; $wpdb->query( 'COMMIT' ); };
  add_action( 'woocommerce_create_refund', $nested_commit ); $guarded_commit = mg_commit( $guard_order->get_id(), $gb, $gq ); remove_action( 'woocommerce_create_refund', $nested_commit );
  mg_check( 503 === $guarded_commit->get_status() && 0 === count( wc_get_order( $guard_order->get_id() )->get_refunds() ), 'native hook cannot prematurely commit the enclosing refund transaction' );
  foreach ( $orders as $cleanup_id ) { $o = wc_get_order( $cleanup_id ); if ( $o ) { ( new ReserveStock() )->release_stock_for_order( $o ); foreach ( $o->get_refunds() as $r ) { $r->delete( true ); } $o->delete( true ); } foreach ( array( 'payment_lines', 'financial_transactions', 'audit_logs', 'stock_movements', 'sale_requests' ) as $table ) { $wpdb->delete( Schema::table( $table ), array( 'order_id' => $cleanup_id ) ); } } $orders = array();
 }
 echo "Completed $checks management checks.\n";
} finally {
 foreach ( $orders as $id ) { $o = wc_get_order( $id ); if ( $o ) { foreach ( $o->get_refunds() as $r ) { $r->delete( true ); } $o->delete( true ); } foreach ( array( 'financial_transactions', 'stock_movements', 'audit_logs' ) as $t ) { $wpdb->delete( Schema::table( $t ), array( 'order_id' => $id ) ); } }
 if ( $created_class ) { WC_Tax::delete_tax_class_by( 'slug', 'upos-mg-tax' ); }
 foreach ( $rates as $rate_id ) { WC_Tax::_delete_tax_rate( $rate_id ); }
 foreach ( $products as $id ) { $p = wc_get_product( $id ); if ( $p ) { $p->delete( true ); } $wpdb->delete( Schema::table( 'stock_movements' ), array( 'product_id' => $id ) ); $wpdb->delete( Schema::table( 'audit_logs' ), array( 'object_type' => 'product', 'object_id' => $id ) ); }
 $wpdb->delete( Schema::table( 'operation_requests' ), array( 'created_by' => $admin->ID ) );
 require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $users as $id ) { wp_delete_user( $id ); }
 foreach ( $original as $name => $value ) { null === $value ? delete_option( $name ) : update_option( $name, $value ); }
}
