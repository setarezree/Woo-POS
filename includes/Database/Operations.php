<?php
namespace StarZarei\UniversalPOS\Database;

use StarZarei\UniversalPOS\POS\SaleInput;
use StarZarei\UniversalPOS\POS\SaleException;

defined( 'ABSPATH' ) || exit;

/** Only request hashes and result IDs are stored; WooCommerce owns the business records. */
final class Operations {
 public static function run( string $kind, string $key, array $body, array $resources, callable $callback ): array {
  $hash = hash( 'sha256', get_current_user_id() . ':' . $kind . ':' . SaleInput::key( $key ) );
  $payload = SaleInput::hash( $body );
  $resources[] = 'operation:' . $hash;
  return AtomicOperation::run( $resources, static function () use ( $kind, $hash, $payload, $callback ) {
   global $wpdb;
   $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE request_key = %s', Schema::table( 'operation_requests' ), $hash ) );
   if ( $row ) {
    if ( ! hash_equals( $row->payload_hash, $payload ) ) { throw new SaleException( 'upos_operation_conflict', __( 'This request key was already used with different details.', 'universal-woocommerce-pos' ), 409 ); }
    return json_decode( $row->result_ids, true, 512, JSON_THROW_ON_ERROR );
   }
   $result = $callback();
   if ( false === $wpdb->insert( Schema::table( 'operation_requests' ), array( 'request_key' => $hash, 'payload_hash' => $payload, 'kind' => $kind, 'result_ids' => wp_json_encode( $result ), 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) ) ) { throw new \RuntimeException( 'Cannot persist operation request.' ); }
   return $result;
  } );
 }
 public static function lookup( string $kind, string $key, bool $abandon = false ): array {
  if ( preg_match( '/^stock:([1-9][0-9]*)$/D', $kind, $m ) ) { \StarZarei\UniversalPOS\Inventory\InventoryService::authorize( true, (int) $m[1] ); }
  elseif ( preg_match( '/^(refund|order):([1-9][0-9]*)(?::(?:note|status))?$/D', $kind, $m ) ) { \StarZarei\UniversalPOS\Orders\OrderService::order( (int) $m[2], 'refund' === $m[1] ); }
  else { self::invalid(); }
  $hash = hash( 'sha256', get_current_user_id() . ':' . $kind . ':' . SaleInput::key( $key ) );
  return AtomicOperation::run( 'operation:' . $hash, static function () use ( $kind, $hash, $abandon ) {
   global $wpdb;
   $row = $wpdb->get_row( $wpdb->prepare( 'SELECT result_ids FROM %i WHERE request_key = %s', Schema::table( 'operation_requests' ), $hash ) );
   if ( $row ) { return array_merge( array( 'found' => true ), json_decode( $row->result_ids, true, 512, JSON_THROW_ON_ERROR ) ); }
   if ( $abandon ) {
    if ( false === $wpdb->insert( Schema::table( 'operation_requests' ), array( 'request_key' => $hash, 'payload_hash' => hash( 'sha256', 'abandoned' ), 'kind' => $kind, 'result_ids' => '{"abandoned":true}', 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) ) ) { throw new \RuntimeException( 'Cannot abandon operation.' ); }
    return array( 'found' => true, 'abandoned' => true );
   }
   return array( 'found' => false );
  } );
 }
 public static function audit( string $action, string $type, int $id, array $context, int $order_id = 0 ): void {
  global $wpdb;
  if ( false === $wpdb->insert( Schema::table( 'audit_logs' ), array( 'action' => $action, 'object_type' => $type, 'object_id' => $id, 'order_id' => $order_id ?: null, 'context' => wp_json_encode( $context ), 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) ) ) { throw new \RuntimeException( 'Cannot persist management audit.' ); }
 }
 public static function fields( array $input, array $allowed ): void {
  if ( array_diff( array_keys( $input ), $allowed ) ) { self::invalid(); }
 }
 public static function text( $value, int $max = 1000, bool $required = false ): string {
  if ( ! is_string( $value ) || mb_strlen( $value ) > $max ) { self::invalid(); }
  $value = sanitize_textarea_field( $value );
  if ( $required && '' === trim( $value ) ) { self::invalid(); }
  return $value;
 }
 public static function invalid(): void { throw new SaleException( 'upos_management_invalid', __( 'Review the required fields and enter valid values.', 'universal-woocommerce-pos' ) ); }
 public static function denied(): void { throw new SaleException( 'upos_forbidden', __( 'You do not have access to this operation.', 'universal-woocommerce-pos' ), rest_authorization_required_code() ); }
}
