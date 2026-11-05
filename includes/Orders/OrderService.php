<?php
namespace StarZarei\UniversalPOS\Orders;

use StarZarei\UniversalPOS\Database\Operations;
use StarZarei\UniversalPOS\Database\Schema;
use StarZarei\UniversalPOS\Inventory\InventoryService;
use StarZarei\UniversalPOS\POS\SaleException;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Checkout\Helpers\ReserveStock;

defined( 'ABSPATH' ) || exit;

final class OrderService {
 public static function authorize( int $id = 0, bool $refund = false ): void {
  if ( ! current_user_can( 'upos_view_orders' ) || ! current_user_can( 'edit_shop_orders' ) || ( $id && ! current_user_can( 'edit_shop_order', $id ) ) || ( $refund && ! current_user_can( 'upos_refund_orders' ) ) ) { Operations::denied(); }
 }
 public static function order( int $id, bool $refund = false ): \WC_Order {
  self::authorize( $id, $refund ); $order = wc_get_order( $id );
  if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund || 'shop_order' !== $order->get_type() ) { throw new SaleException( 'upos_not_found', __( 'The requested item was not found.', 'universal-woocommerce-pos' ), 404 ); }
  return $order;
 }
 public static function legacy_query( array $query, array $vars ): array {
  if ( isset( $vars['upos_channel'] ) ) { $query['meta_query'][] = self::channel_query( $vars['upos_channel'] ); }
  return $query;
 }
 private static function channel_query( string $channel ): array {
  return 'pos' === $channel ? array( 'key' => SalesChannel::META_KEY, 'value' => 'pos' ) : array( 'relation' => 'OR', array( 'key' => SalesChannel::META_KEY, 'value' => 'pos', 'compare' => '!=' ), array( 'key' => SalesChannel::META_KEY, 'compare' => 'NOT EXISTS' ) );
 }
 public function listing( array $input ): array {
  self::authorize();
  $args = array( 'type' => 'shop_order', 'limit' => min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) ), 'page' => max( 1, (int) ( $input['page'] ?? 1 ) ), 'paginate' => true, 'orderby' => 'date', 'order' => 'DESC' );
  if ( ! empty( $input['status'] ) ) { if ( ! isset( wc_get_order_statuses()[ 'wc-' . $input['status'] ] ) ) { Operations::invalid(); } $args['status'] = $input['status']; }
  if ( ! empty( $input['channel'] ) ) {
   if ( ! in_array( $input['channel'], array( 'online', 'pos' ), true ) ) { Operations::invalid(); }
   if ( OrderUtil::custom_orders_table_usage_is_enabled() ) { $args['meta_query'] = array( self::channel_query( $input['channel'] ) ); } else { $args['upos_channel'] = $input['channel']; }
  }
  if ( ! empty( $input['search'] ) ) { $term = Operations::text( $input['search'], 100 ); $args['include'] = wc_order_search( $term ) ?: array( 0 ); }
  $from = ! empty( $input['from'] ) ? InventoryService::date( $input['from'] ) : '';
  $to = ! empty( $input['to'] ) ? InventoryService::date( $input['to'] ) : '';
  if ( $from && $to && $from > $to ) { Operations::invalid(); }
  if ( $from || $to ) { $args['date_created'] = $from && $to ? $from . '...' . $to : ( $from ? '>=' . $from : '<=' . $to ); }
  $result = wc_get_orders( $args ); $orders = array();
  foreach ( $result->orders as $order ) { if ( current_user_can( 'edit_shop_order', $order->get_id() ) ) { $orders[] = self::summary( $order ); } }
  return array( 'orders' => $orders, 'total' => $result->total, 'pages' => $result->max_num_pages );
 }
 public static function summary( \WC_Order $order ): array {
  return array( 'id' => $order->get_id(), 'number' => $order->get_order_number(), 'date' => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '', 'status' => $order->get_status(), 'channel' => SalesChannel::get( $order ), 'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ), 'phone' => $order->get_billing_phone(), 'currency' => $order->get_currency(), 'total' => $order->get_total(), 'refunded' => $order->get_total_refunded(), 'cashier_id' => (int) $order->get_meta( SalesChannel::CASHIER_KEY, true ), 'payment_method' => $order->get_payment_method_title(), 'paid' => (bool) $order->get_date_paid() );
 }
 public function detail( int $id ): array {
  $order = self::order( $id ); $data = self::summary( $order ); $data['items'] = array();
  foreach ( $order->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $item_id => $item ) {
   $product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : false;
   $data['items'][] = array( 'item_id' => $item_id, 'type' => $item->get_type(), 'name' => $item->get_name(), 'quantity' => $item->get_quantity(), 'refunded_quantity' => abs( $order->get_qty_refunded_for_item( $item_id, $item->get_type() ) ), 'total' => $item->get_total(), 'tax' => $item->get_total_tax(), 'product_id' => $product ? $product->get_id() : 0 );
  }
  $data['remaining_refund'] = $order->get_remaining_refund_amount();
  $data['refunds'] = array_map( static fn( $refund ) => array( 'id' => $refund->get_id(), 'amount' => $refund->get_amount(), 'reason' => $refund->get_reason(), 'conditions' => $refund->get_meta( '_upos_return_conditions', true ), 'refunded_by' => $refund->get_refunded_by() ), $order->get_refunds() );
  $data['notes'] = array_map( static fn( $note ) => array( 'id' => $note->id, 'content' => html_entity_decode( wp_strip_all_tags( $note->content ), ENT_QUOTES, 'UTF-8' ), 'date' => $note->date_created->date( 'c' ) ), wc_get_order_notes( array( 'order_id' => $id, 'type' => 'internal', 'limit' => 20 ) ) );
  global $wpdb;
  $data['payments'] = $wpdb->get_results( $wpdb->prepare( 'SELECT amount, currency, method, reference, created_at FROM %i WHERE order_id = %d ORDER BY id', Schema::table( 'payment_lines' ), $id ), ARRAY_A );
  return $data;
 }
 public function mutate( int $id, string $action, array $body, string $key ): array {
  self::order( $id );
  if ( 'note' === $action ) { Operations::fields( $body, array( 'note' ) ); $body['note'] = Operations::text( $body['note'] ?? '', 1000, true ); }
  else { Operations::fields( $body, array( 'status', 'expected_status' ) ); if ( ! is_string( $body['expected_status'] ?? null ) || ! in_array( $body['status'] ?? '', array( 'processing', 'completed', 'cancelled' ), true ) ) { Operations::invalid(); } }
  Operations::run( 'order:' . $id . ':' . $action, $key, $body, array( 'order:' . $id ), static function () use ( $id, $action, $body ) {
   $order = self::order( $id );
   if ( 'note' === $action ) { if ( ! $order->add_order_note( $body['note'], false, true ) ) { throw new \RuntimeException( 'Cannot save order note.' ); } }
   else {
    if ( $order->get_status() !== $body['expected_status'] ) { throw new SaleException( 'upos_order_changed', __( 'The order changed. Refresh its details before continuing.', 'universal-woocommerce-pos' ), 409 ); }
    if ( 'cancelled' === $body['status'] ) {
     global $wpdb;
     $receipts = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id = %d', Schema::table( 'payment_lines' ), $id ) );
     if ( $order->get_date_paid() || $receipts || $order->get_total_refunded() > 0 || ! $order->has_status( array( 'pending', 'on-hold', 'failed' ) ) ) { throw new SaleException( 'upos_refund_required', __( 'A received payment requires a refund; it cannot be cancelled here.', 'universal-woocommerce-pos' ), 409 ); }
    } elseif ( ! $order->is_paid() || ! $order->get_date_paid() ) { throw new SaleException( 'upos_unpaid_status', __( 'Record real payment before changing a paid-order status.', 'universal-woocommerce-pos' ), 409 ); }
    $order->update_status( $body['status'] );
    if ( 'cancelled' === $body['status'] ) { ( new ReserveStock() )->release_stock_for_order( $order ); }
   }
   Operations::audit( 'order_' . $action, 'order', $id, $body, $id ); return array( 'order_id' => $id );
  } );
  return $this->detail( $id );
 }
}
