<?php
namespace StarZarei\UniversalPOS\POS;

use StarZarei\UniversalPOS\Core\Module;
use StarZarei\UniversalPOS\Database\AtomicOperation;
use StarZarei\UniversalPOS\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class ParkedCarts implements Module {
	public function register(): void {
		add_action( 'upos_purge_parked_carts', static function () {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %s', Schema::table( 'parked_carts' ), current_time( 'mysql', true ) ) );
		} );
		if ( function_exists( 'as_next_scheduled_action' ) && false === as_next_scheduled_action( 'upos_purge_parked_carts', array(), 'universal-pos' ) ) {
			as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, 'upos_purge_parked_carts', array(), 'universal-pos', true );
		}
	}

	public static function authorize(): void {
		if ( ! App::allowed() ) { throw new SaleException( 'upos_forbidden', __( 'You do not have access to this operation.', 'universal-woocommerce-pos' ), rest_authorization_required_code() ); }
	}

	public static function hash( string $key ): string { return hash( 'sha256', get_current_user_id() . ':' . SaleInput::key( $key ) ); }

	public static function save( string $key, array $input ): array {
		self::authorize();
		if ( array_diff( array_keys( $input ), array( 'label', 'basket', 'replaces' ) ) || ! is_array( $input['basket'] ?? null ) || ! is_string( $input['label'] ?? '' ) || mb_strlen( $input['label'] ?? '' ) > 100 ) {
			throw new SaleException( 'upos_cart_invalid', __( 'Provide a basket and a label of up to 100 characters.', 'universal-woocommerce-pos' ) );
		}
		$basket = SaleInput::prepare( $input['basket'], false ); unset( $basket['parked_cart_key'] );
		$label = sanitize_text_field( $input['label'] ?? '' );
		$replaces = $input['replaces'] ?? '';
		if ( ! is_string( $replaces ) ) { self::conflict(); }
		$resources = array( 'cart:' . self::hash( $key ) );
		if ( '' !== $replaces ) { $resources[] = 'cart:' . self::hash( $replaces ); if ( $replaces === $key ) { self::conflict(); } }
		$hash = self::hash( $key ); $request_hash = SaleInput::hash( array( 'label' => $label, 'basket' => $basket, 'replaces' => $replaces ) );
		return AtomicOperation::run( $resources, function () use ( $key, $hash, $request_hash, $basket, $label, $replaces ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE key_hash = %s', Schema::table( 'parked_carts' ), $hash ) );
			if ( $row ) {
				if ( ! hash_equals( $row->request_hash, $request_hash ) ) { self::conflict(); }
				return self::get( $key );
			}
			if ( '' !== $replaces ) { self::row( $replaces, true ); }
			if ( false === $wpdb->insert( Schema::table( 'parked_carts' ), array( 'key_hash' => $hash, 'cart_key' => $key, 'request_hash' => $request_hash, 'label' => $label, 'payload' => wp_json_encode( $basket ), 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) ) ) ) { throw new \RuntimeException( 'Cannot save parked basket.' ); }
			if ( '' !== $replaces && false === $wpdb->update( Schema::table( 'parked_carts' ), array( 'status' => 'deleted', 'payload' => '{}', 'label' => '' ), array( 'key_hash' => self::hash( $replaces ) ) ) ) { throw new \RuntimeException( 'Cannot replace parked basket.' ); }
			return self::get( $key );
		} );
	}

	public static function get( string $key ): array {
		self::authorize(); $row = self::row( $key );
		$basket = SaleInput::prepare( json_decode( $row->payload, true, 512, JSON_THROW_ON_ERROR ), false );
		return array( 'key' => $row->cart_key, 'label' => $row->label, 'basket' => $basket, 'expires_at' => str_replace( ' ', 'T', $row->expires_at ) . 'Z' );
	}

	public static function restore( string $key ): array {
		self::authorize(); return AtomicOperation::run( 'cart:' . self::hash( $key ), static fn() => self::get( $key ) );
	}

	public static function listing(): array {
		self::authorize(); global $wpdb;
		return array( 'carts' => $wpdb->get_results( $wpdb->prepare( "SELECT cart_key AS `key`, label, created_at, expires_at FROM %i WHERE created_by = %d AND status = 'parked' AND expires_at > %s ORDER BY created_at DESC LIMIT 50", Schema::table( 'parked_carts' ), get_current_user_id(), current_time( 'mysql', true ) ), ARRAY_A ) );
	}

	public static function discard( string $key ): array {
		self::authorize(); $hash = self::hash( $key );
		return AtomicOperation::run( 'cart:' . $hash, function () use ( $hash, $key ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE key_hash = %s', Schema::table( 'parked_carts' ), $hash ) );
			if ( $row && 'consumed' === $row->status ) { self::conflict(); }
			if ( $row ) {
				$result = $wpdb->update( Schema::table( 'parked_carts' ), array( 'status' => 'deleted', 'payload' => '{}', 'label' => '' ), array( 'key_hash' => $hash ) );
			} else {
				$result = $wpdb->insert( Schema::table( 'parked_carts' ), array( 'key_hash' => $hash, 'cart_key' => $key, 'request_hash' => hash( 'sha256', 'deleted' ), 'status' => 'deleted', 'payload' => '{}', 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) ) );
			}
			if ( false === $result ) { throw new \RuntimeException( 'Cannot discard parked basket.' ); }
			return array( 'deleted' => true );
		} );
	}

	/** Called only inside the sale transaction. The row lock serializes different checkout keys. */
	public static function consume( string $key, int $order_id ): void {
		self::authorize(); self::row( $key, true ); global $wpdb;
		if ( 1 !== $wpdb->update( Schema::table( 'parked_carts' ), array( 'status' => 'consumed', 'order_id' => $order_id, 'payload' => '{}', 'label' => '' ), array( 'key_hash' => self::hash( $key ), 'status' => 'parked' ) ) ) { self::conflict(); }
	}

	private static function row( string $key, bool $lock = false ): object {
		global $wpdb;
		$sql = 'SELECT * FROM %i WHERE key_hash = %s AND created_by = %d' . ( $lock ? ' FOR UPDATE' : '' );
		$row = $wpdb->get_row( $wpdb->prepare( $sql, Schema::table( 'parked_carts' ), self::hash( $key ), get_current_user_id() ) );
		if ( ! $row ) { throw new SaleException( 'upos_cart_not_found', __( 'The saved basket was not found.', 'universal-woocommerce-pos' ), 404 ); }
		if ( 'parked' !== $row->status || $row->expires_at <= current_time( 'mysql', true ) ) { self::conflict(); }
		return $row;
	}

	private static function conflict(): void { throw new SaleException( 'upos_cart_conflict', __( 'This basket has expired, was deleted, or has already been used. Refresh the saved basket list.', 'universal-woocommerce-pos' ), 409 ); }
}
