<?php
namespace StarZarei\UniversalPOS\Inventory;

use StarZarei\UniversalPOS\Database\Operations;
use StarZarei\UniversalPOS\Database\Schema;
use StarZarei\UniversalPOS\POS\SaleException;
use StarZarei\UniversalPOS\Products\ProductRepository;
use Automattic\WooCommerce\Checkout\Helpers\ReserveStock;

defined( 'ABSPATH' ) || exit;

final class InventoryService {
 public static function authorize( bool $write = false, int $id = 0 ): void {
  if ( ! current_user_can( 'upos_view_inventory' ) || ! current_user_can( 'edit_products' ) || ( $write && ( ! current_user_can( 'upos_adjust_stock' ) || ! current_user_can( 'edit_product', $id ) ) ) ) { Operations::denied(); }
 }
 public function products( array $args ): array {
  self::authorize();
  $query = array( 'limit' => min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) ), 'page' => max( 1, (int) ( $args['page'] ?? 1 ) ), 'paginate' => true, 'status' => array( 'publish', 'draft', 'pending', 'private' ), 'orderby' => 'ID', 'order' => 'DESC' );
  if ( ! empty( $args['search'] ) ) { $query['s'] = sanitize_text_field( $args['search'] ); }
  if ( ! empty( $args['sku'] ) ) { $query['sku'] = sanitize_text_field( $args['sku'] ); }
  if ( ! empty( $args['parent'] ) ) { $query['parent'] = absint( $args['parent'] ); $query['type'] = 'variation'; }
  if ( in_array( $args['stock_status'] ?? '', array( 'instock', 'outofstock', 'onbackorder' ), true ) ) { $query['stock_status'] = $args['stock_status']; }
  $result = wc_get_products( $query ); $products = array();
  foreach ( $result->products as $product ) { if ( current_user_can( 'edit_product', $product->get_id() ) ) { $products[] = self::product( $product ); } }
  return array( 'products' => $products, 'total' => $result->total, 'pages' => $result->max_num_pages );
 }
 public function detail( int $id ): array {
  self::authorize(); $product = wc_get_product( $id ); if ( ! $product ) { self::missing(); } if ( ! current_user_can( 'edit_product', $id ) ) { Operations::denied(); } return self::product( $product );
 }
 public static function product( \WC_Product $product ): array {
  $stock = wc_get_product( $product->get_stock_managed_by_id() );
  return array_merge( ( new ProductRepository() )->serialize( $product ), array( 'physical_stock' => $stock ? $stock->get_stock_quantity() : null, 'low_stock_amount' => $stock ? wc_get_low_stock_amount( $stock ) : null, 'low_stock' => $stock && $stock->managing_stock() && $stock->get_stock_quantity() <= wc_get_low_stock_amount( $stock ) ) );
 }
 public function adjust( int $id, array $body, string $key ): array {
  self::authorize( true, $id ); Operations::fields( $body, array( 'type', 'quantity', 'expected_stock', 'reason', 'low_stock_amount' ) );
  $types = array( 'receipt', 'damage', 'waste', 'adjustment', 'stocktake' );
  if ( ! in_array( $body['type'] ?? '', $types, true ) || ! is_int( $body['quantity'] ?? null ) || ! is_int( $body['expected_stock'] ?? null ) || $body['quantity'] < 0 || $body['quantity'] > 1000000000 ) { Operations::invalid(); }
  if ( isset( $body['low_stock_amount'] ) && ( ! is_int( $body['low_stock_amount'] ) || $body['low_stock_amount'] < 0 || $body['low_stock_amount'] > 1000000000 ) ) { Operations::invalid(); }
  $body['reason'] = Operations::text( $body['reason'] ?? '', 1000, true );
  $product = wc_get_product( $id ); if ( ! $product ) { self::missing(); }
  $stock_id = $product->get_stock_managed_by_id();
  $result = Operations::run( 'stock:' . $id, $key, $body, array( 'stock:' . $stock_id ), static function () use ( $id, $stock_id, $body ) {
   self::authorize( true, $stock_id );
   $product = wc_get_product( $id ); $stock = wc_get_product( $stock_id );
   if ( ! $product || ! $stock || ! $product->managing_stock() || $product->get_stock_managed_by_id() !== $stock_id ) { throw new SaleException( 'upos_stock_unmanaged', __( 'Enable native stock management before adjusting this product.', 'universal-woocommerce-pos' ), 409 ); }
   // Native zero increment locks the same stock row used by WooCommerce reservations.
   Ledger::silence( static fn() => wc_update_product_stock( $stock, 0, 'increase' ) );
   $stock = wc_get_product( $stock_id );
   $old = $stock->get_stock_quantity();
   if ( ! is_numeric( $old ) || floor( (float) $old ) !== (float) $old || (int) $old !== $body['expected_stock'] ) { self::conflict(); }
   $new = match ( $body['type'] ) { 'receipt' => $old + $body['quantity'], 'damage', 'waste' => $old - $body['quantity'], default => $body['quantity'] };
   if ( $new < 0 || $new > 1000000000 || $new < ( new ReserveStock() )->get_reserved_stock( $stock ) ) { throw new SaleException( 'upos_stock_reserved', __( 'Stock cannot be negative or less than current WooCommerce reservations.', 'universal-woocommerce-pos' ), 409 ); }
   Ledger::context( array( 'type' => $body['type'], 'notes' => $body['reason'], 'product_id' => $product->is_type( 'variation' ) ? $product->get_parent_id() : $id, 'variation_id' => $product->is_type( 'variation' ) ? $id : 0 ), static function () use ( $stock, $new ) {
    if ( false === wc_update_product_stock( $stock, $new, 'set' ) ) { throw new \RuntimeException( 'Cannot update stock.' ); }
   } );
   if ( isset( $body['low_stock_amount'] ) ) { $stock->set_low_stock_amount( $body['low_stock_amount'] ); $stock->save(); }
   Operations::audit( 'stock_' . $body['type'], 'product', $id, array( 'before' => $old, 'after' => $new, 'reason' => $body['reason'] ) );
   return array( 'product_id' => $id );
  } );
  return self::product( wc_get_product( $result['product_id'] ) );
 }
 public function history( array $args, int $limit = 50 ): array {
  self::authorize(); global $wpdb;
  $where = array( '1=1' ); $values = array();
  if ( ! empty( $args['product_id'] ) ) { $where[] = '(product_id = %d OR variation_id = %d)'; $values[] = absint( $args['product_id'] ); $values[] = absint( $args['product_id'] ); }
  if ( ! empty( $args['order_id'] ) ) { $where[] = 'order_id = %d'; $values[] = absint( $args['order_id'] ); }
  if ( ! empty( $args['type'] ) ) { $where[] = 'type = %s'; $values[] = sanitize_key( $args['type'] ); }
  foreach ( array( 'from' => '>=', 'to' => '<=' ) as $field => $operator ) {
   if ( ! empty( $args[ $field ] ) ) { $date = self::date( $args[ $field ] ); $where[] = 'created_at ' . $operator . ' %s'; $values[] = $date . ( 'to' === $field ? ' 23:59:59' : ' 00:00:00' ); }
  }
  $limit = min( 5000, max( 1, $limit ) ); $page = max( 1, (int) ( $args['page'] ?? 1 ) );
  $sql = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
  return array( 'movements' => $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( Schema::table( 'stock_movements' ) ), $values, array( $limit, ( $page - 1 ) * $limit ) ) ), ARRAY_A ), 'page' => $page );
 }
 public static function date( $value ): string {
  if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) || gmdate( 'Y-m-d', strtotime( $value . ' UTC' ) ) !== $value ) { Operations::invalid(); } return $value;
 }
 private static function missing(): void { throw new SaleException( 'upos_not_found', __( 'The requested item was not found.', 'universal-woocommerce-pos' ), 404 ); }
 private static function conflict(): void { throw new SaleException( 'upos_stock_changed', __( 'Stock changed. Refresh the product and review your adjustment.', 'universal-woocommerce-pos' ), 409 ); }
}
