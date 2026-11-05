<?php
namespace StarZarei\UniversalPOS\Orders;

use StarZarei\UniversalPOS\Database\AtomicOperation;
use StarZarei\UniversalPOS\Database\Operations;
use StarZarei\UniversalPOS\Database\Schema;
use StarZarei\UniversalPOS\Inventory\Ledger;
use StarZarei\UniversalPOS\Payments\Money;
use StarZarei\UniversalPOS\Payments\ManualPayments;
use StarZarei\UniversalPOS\POS\SaleException;

defined( 'ABSPATH' ) || exit;

final class RefundService {
 private static function input( array $input, bool $commit ): array {
  Operations::fields( $input, array_merge( array( 'mode', 'items', 'reason' ), $commit ? array( 'quote_hash', 'expected_amount', 'received', 'method', 'reference' ) : array() ) );
  if ( ! in_array( $input['mode'] ?? '', array( 'full', 'partial' ), true ) || ! is_array( $input['items'] ?? null ) || ! array_is_list( $input['items'] ) || count( $input['items'] ) > 100 ) { Operations::invalid(); }
  $items = array();
  foreach ( $input['items'] as $line ) {
   if ( ! is_array( $line ) ) { Operations::invalid(); } Operations::fields( $line, array( 'item_id', 'quantity', 'condition' ) );
   if ( ! is_int( $line['item_id'] ?? null ) || $line['item_id'] < 1 || isset( $items[ $line['item_id'] ] ) || ! is_int( $line['quantity'] ?? null ) || $line['quantity'] < 1 || $line['quantity'] > 1000 || ! in_array( $line['condition'] ?? '', array( 'saleable', 'damaged', 'inspection' ), true ) ) { Operations::invalid(); }
   $items[ $line['item_id'] ] = $line;
  }
  ksort( $items ); $input['items'] = array_values( $items ); $input['reason'] = Operations::text( $input['reason'] ?? '', 1000, true );
  if ( $commit ) {
   if ( true !== ( $input['received'] ?? false ) || ! is_string( $input['quote_hash'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $input['quote_hash'] ) || ! is_string( $input['expected_amount'] ?? null ) || ! is_string( $input['method'] ?? null ) || ! isset( ManualPayments::methods()[ $input['method'] ] ) ) { Operations::invalid(); }
   $input['reference'] = Operations::text( $input['reference'] ?? '', 191 );
   if ( 'cash' !== $input['method'] && '' === $input['reference'] ) { Operations::invalid(); }
  }
  return $input;
 }
 public function quote( int $id, array $body ): array {
  OrderService::order( $id, true ); $input = self::input( $body, false );
  return AtomicOperation::run( 'order:' . $id, static fn() => self::calculate( OrderService::order( $id, true ), $input ) );
 }
 private static function minor( $amount ): int { return Money::minor( wc_format_decimal( $amount, wc_get_price_decimals() ) ); }
 private static function decimal( int $minor ): string {
  $decimals = wc_get_price_decimals(); $digits = str_pad( (string) $minor, $decimals + 1, '0', STR_PAD_LEFT );
  return $decimals ? substr( $digits, 0, -$decimals ) . '.' . substr( $digits, -$decimals ) : $digits;
 }
 private static function share( int $remaining, int $quantity, int $available ): int { return $quantity === $available ? $remaining : intdiv( $remaining * $quantity + intdiv( $available, 2 ), $available ); }
 private static function calculate( \WC_Order $order, array $input ): array {
  if ( ! $order->get_date_paid() || ! $order->has_status( array( 'processing', 'completed', 'refunded' ) ) || $order->get_currency() !== get_woocommerce_currency() || ( '' !== $order->get_meta( '_upos_price_decimals', true ) && (int) $order->get_meta( '_upos_price_decimals', true ) !== wc_get_price_decimals() ) ) {
   throw new SaleException( 'upos_refund_unavailable', __( 'Refunds require a paid order and matching store currency precision.', 'universal-woocommerce-pos' ), 409 );
  }
  $raw_balance = (float) $order->get_total() - (float) $order->get_total_refunded();
  if ( abs( $raw_balance - (float) wc_format_decimal( $raw_balance, wc_get_price_decimals() ) ) > 0.00000001 ) { throw new SaleException( 'upos_refund_unavailable', __( 'Refunds require a paid order and matching store currency precision.', 'universal-woocommerce-pos' ), 409 ); }
  $selected = array_column( $input['items'], null, 'item_id' ); $lines = array(); $conditions = array(); $sum = 0;
  $all = $order->get_items( array( 'line_item', 'fee', 'shipping' ) );
  foreach ( $selected as $id => $line ) { if ( ! isset( $all[ $id ] ) || ! $all[ $id ] instanceof \WC_Order_Item_Product ) { Operations::invalid(); } }
  foreach ( $all as $id => $item ) {
   $type = $item->get_type(); $is_product = $item instanceof \WC_Order_Item_Product;
   $available = $is_product ? $item->get_quantity() + $order->get_qty_refunded_for_item( $id ) : 0;
   if ( $is_product && ( floor( (float) $available ) !== (float) $available || $available < 0 ) ) { Operations::invalid(); }
   if ( $is_product && $available > 0 && 'full' === $input['mode'] && ! isset( $selected[ $id ] ) ) { Operations::invalid(); }
   if ( $is_product && ! isset( $selected[ $id ] ) ) { continue; }
   if ( ! $is_product && 'full' !== $input['mode'] ) { continue; }
   $quantity = $is_product ? $selected[ $id ]['quantity'] : 0;
   if ( $is_product && ( $quantity > $available || ( 'full' === $input['mode'] && $quantity !== (int) $available ) ) ) { throw new SaleException( 'upos_return_quantity', __( 'The requested quantity has already been returned or exceeds the order.', 'universal-woocommerce-pos' ), 409 ); }
   if ( $is_product && 'saleable' === $selected[ $id ]['condition'] && ! $item->get_product() ) { throw new SaleException( 'upos_restock_unavailable', __( 'The original stock reduction cannot support this restock. Review the order stock history.', 'universal-woocommerce-pos' ), 409 ); }
   $remaining = self::minor( $item->get_total() ) - self::minor( $order->get_total_refunded_for_item( $id, $type ) );
   if ( $remaining < 0 ) { Operations::invalid(); }
   $amount = $is_product ? self::share( $remaining, $quantity, (int) $available ) : $remaining;
   $taxes = array();
   foreach ( $item->get_taxes()['total'] ?? array() as $rate => $total ) {
    $tax_remaining = self::minor( $total ) - self::minor( $order->get_tax_refunded_for_item( $id, $rate, $type ) );
    if ( $tax_remaining < 0 ) { Operations::invalid(); }
    $tax = $is_product ? self::share( $tax_remaining, $quantity, (int) $available ) : $tax_remaining;
    $taxes[ $rate ] = self::decimal( $tax ); $sum += $tax;
   }
   $sum += $amount;
   $lines[ $id ] = array( 'qty' => $quantity, 'refund_total' => self::decimal( $amount ), 'refund_tax' => $taxes );
   if ( $is_product ) { $conditions[ $id ] = $selected[ $id ]['condition']; }
  }
  if ( ! $lines || $sum > self::minor( $order->get_remaining_refund_amount() ) ) { throw new SaleException( 'upos_refund_balance', __( 'The refund no longer matches the remaining order balance. Refresh the order.', 'universal-woocommerce-pos' ), 409 ); }
  // Full means the remaining financial balance too, including shipping/fees and prior amount-only refunds.
  if ( 'full' === $input['mode'] && $sum !== self::minor( $order->get_remaining_refund_amount() ) ) { throw new SaleException( 'upos_refund_balance', __( 'The refund no longer matches the remaining order balance. Refresh the order.', 'universal-woocommerce-pos' ), 409 ); }
  $amount = self::decimal( $sum );
  $hash = hash( 'sha256', wp_json_encode( array( $order->get_id(), $order->get_currency(), wc_get_price_decimals(), $order->get_total(), $order->get_status(), array_map( static fn( $r ) => $r->get_id(), $order->get_refunds() ), $input['mode'], $input['reason'], $lines, $conditions, $amount ) ) );
  return array( 'order_id' => $order->get_id(), 'amount' => $amount, 'currency' => $order->get_currency(), 'quote_hash' => $hash, 'lines' => $lines, 'conditions' => $conditions );
 }
 public function create( int $id, array $body, string $key ): array {
  OrderService::order( $id, true ); $input = self::input( $body, true );
  $result = Operations::run( 'refund:' . $id, $key, $input, array( 'order:' . $id ), static function () use ( $id, $input ) {
   $order = OrderService::order( $id, true ); $quote = self::calculate( $order, $input );
   if ( ! hash_equals( $quote['quote_hash'], $input['quote_hash'] ) || Money::minor( $input['expected_amount'] ) !== Money::minor( $quote['amount'] ) ) { throw new SaleException( 'upos_refund_changed', __( 'The refund quote changed. Review a fresh quote before returning funds.', 'universal-woocommerce-pos' ), 409 ); }
   // Funds are explicitly confirmed as already returned; never call a gateway inside a DB transaction.
   $refund = wc_create_refund( array( 'order_id' => $id, 'amount' => $quote['amount'], 'reason' => $input['reason'], 'line_items' => $quote['lines'], 'refund_payment' => false, 'restock_items' => false ) );
   if ( is_wp_error( $refund ) || ! $refund instanceof \WC_Order_Refund ) { throw new \RuntimeException( 'WooCommerce refund failed.' ); }
   foreach ( $quote['conditions'] as $item_id => $condition ) {
    if ( 'saleable' !== $condition ) { continue; }
    $item = $order->get_item( $item_id ); $product = $item->get_product(); $qty = $quote['lines'][ $item_id ]['qty'];
    if ( ! $product || ! $product->managing_stock() ) { continue; }
    if ( (float) $item->get_meta( '_reduced_stock', true ) < $qty ) { throw new SaleException( 'upos_restock_unavailable', __( 'The original stock reduction cannot support this restock. Review the order stock history.', 'universal-woocommerce-pos' ), 409 ); }
    Ledger::context( array( 'type' => 'refund_restock', 'order_id' => $id, 'product_id' => $item->get_product_id(), 'variation_id' => $item->get_variation_id(), 'notes' => $input['reason'] ), static function () use ( $order, $item_id, $quote ) { wc_restock_refunded_items( $order, array( $item_id => $quote['lines'][ $item_id ] ) ); } );
   }
   $refund->update_meta_data( '_upos_return_conditions', $quote['conditions'] );
   $refund->update_meta_data( '_upos_manual_refund', array( 'method' => $input['method'], 'reference' => $input['reference'], 'confirmed_by' => get_current_user_id() ) ); $refund->save();
   if ( Money::minor( $quote['amount'] ) > 0 ) {
    global $wpdb;
    if ( false === $wpdb->insert( Schema::table( 'financial_transactions' ), array( 'idempotency_key' => hash( 'sha256', 'refund:' . $refund->get_id() ), 'type' => 'sale_refund', 'amount' => $quote['amount'], 'currency' => $order->get_currency(), 'method' => $input['method'], 'order_id' => $id, 'refund_id' => $refund->get_id(), 'reference' => $input['reference'], 'notes' => $input['reason'], 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) ) ) { throw new \RuntimeException( 'Cannot persist refund transaction.' ); }
   }
   Operations::audit( 'order_refunded', 'refund', $refund->get_id(), array( 'amount' => $quote['amount'], 'conditions' => $quote['conditions'] ), $id );
   return array( 'order_id' => $id, 'refund_id' => $refund->get_id() );
  } );
  $refund = wc_get_order( $result['refund_id'] );
  if ( ! $refund instanceof \WC_Order_Refund ) { throw new SaleException( 'upos_refund_removed', __( 'The recorded refund was removed externally. Review the order before proceeding.', 'universal-woocommerce-pos' ), 409 ); }
  return array( 'refund_id' => $refund->get_id(), 'amount' => $refund->get_amount(), 'order' => ( new OrderService() )->detail( $id ) );
 }
}
