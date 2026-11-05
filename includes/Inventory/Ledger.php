<?php
namespace StarZarei\UniversalPOS\Inventory;

use StarZarei\UniversalPOS\Core\Module;
use StarZarei\UniversalPOS\Database\Schema;
use StarZarei\UniversalPOS\Orders\SalesChannel;

defined( 'ABSPATH' ) || exit;

/** Observe native stock transitions, then enrich them with native order hooks. */
final class Ledger implements Module {
 private static array $before = array();
 private static array $last = array();
 private static ?array $context = null;
 private static int $silenced = 0;
 public function register(): void {
  foreach ( array( 'product', 'variation' ) as $type ) {
   add_action( 'woocommerce_' . $type . '_before_set_stock', static function ( $product ) {
    if ( self::$silenced ) { return; }
    $previous = wc_get_product( $product->get_id() );
    self::$before[ $product->get_id() ] = $previous ? $previous->get_stock_quantity( 'edit' ) : null;
   }, 1 );
   add_action( 'woocommerce_' . $type . '_set_stock', static function ( $product ) {
    if ( self::$silenced || ! array_key_exists( $product->get_id(), self::$before ) ) { return; }
    $before = self::$before[ $product->get_id() ]; unset( self::$before[ $product->get_id() ] );
    $after = $product->get_stock_quantity( 'edit' );
    if ( null === $after || ( null !== $before && (float) $before === (float) $after ) ) { return; }
    $context = self::$context ?? array();
    $row = array_merge( array( 'product_id' => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(), 'variation_id' => $product->is_type( 'variation' ) ? $product->get_id() : 0, 'type' => null === $before ? 'opening_stock' : 'stock_update', 'order_id' => null, 'notes' => '' ), $context,
     array( 'event_key' => hash( 'sha256', wp_generate_uuid4() ), 'quantity_before' => $before, 'quantity_after' => $after, 'quantity_change' => $after - ( $before ?? 0 ), 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) );
    global $wpdb;
    if ( false === $wpdb->insert( Schema::table( 'stock_movements' ), $row ) ) {
     if ( null !== self::$context ) { throw new \RuntimeException( 'Cannot persist stock movement.' ); }
     wc_get_logger()->error( 'Cannot record a native stock transition.', array( 'source' => 'universal-woocommerce-pos' ) ); return;
    }
    self::$last[ $product->get_id() ] = $wpdb->insert_id;
   }, 30 );
  }
  add_action( 'woocommerce_reduce_order_item_stock', static function ( $item, $change, $order ) {
   if ( self::$silenced || ( 'pos' === SalesChannel::get( $order ) && $order->get_meta( '_upos_payment_key', true ) ) ) { return; }
   self::decorate( $item, $order, SalesChannel::get( $order ) . '_sale' );
  }, 30, 3 );
  add_action( 'woocommerce_restore_order_item_stock', static function ( $item, $new, $old, $order ) { self::decorate( $item, $order, 'order_cancelled' ); }, 30, 4 );
  add_action( 'woocommerce_restock_refunded_item', static function ( $id, $old, $new, $order, $product ) {
   if ( null === self::$context ) { self::tag( $product->get_stock_managed_by_id(), array( 'type' => 'refund_restock', 'order_id' => $order->get_id(), 'product_id' => $product->is_type( 'variation' ) ? $product->get_parent_id() : $id, 'variation_id' => $product->is_type( 'variation' ) ? $id : 0 ) ); }
  }, 30, 5 );
 }
 private static function decorate( $item, $order, string $type ): void {
  $product = $item->get_product(); if ( ! $product || self::$silenced ) { return; }
  self::tag( $product->get_stock_managed_by_id(), array( 'type' => $type, 'order_id' => $order->get_id(), 'product_id' => $item->get_product_id(), 'variation_id' => $item->get_variation_id() ) );
 }
 private static function tag( int $stock_id, array $data ): void {
  if ( ! isset( self::$last[ $stock_id ] ) ) { return; }
  global $wpdb;
  $wpdb->update( Schema::table( 'stock_movements' ), $data, array( 'id' => self::$last[ $stock_id ] ) ); unset( self::$last[ $stock_id ] );
 }
 public static function context( array $context, callable $callback ) {
  $old = self::$context; self::$context = $context;
  try { return $callback(); } finally { self::$context = $old; self::$before = array(); self::$last = array(); }
 }
 public static function silence( callable $callback ) {
  ++self::$silenced;
  try { return $callback(); } finally { --self::$silenced; }
 }
}
