<?php
namespace StarZarei\UniversalPOS\Orders;

use StarZarei\UniversalPOS\Core\Module;

defined( 'ABSPATH' ) || exit;

final class SalesChannel implements Module {
	public const META_KEY = '_upos_sales_channel';
	public const CASHIER_KEY = '_upos_cashier_id';

	public function register(): void {
		// Applies to classic checkout, Store API and CRUD-created orders, including HPOS.
		add_action( 'woocommerce_before_order_object_save', array( $this, 'set_default' ) );
	}

	public function set_default( $order ): void {
		if ( $order instanceof \WC_Order && ! $order->get_meta( self::META_KEY, true ) ) {
			$order->update_meta_data( self::META_KEY, 'online' );
		}
	}

	public static function get( \WC_Order $order ): string {
		return 'pos' === $order->get_meta( self::META_KEY, true ) ? 'pos' : 'online';
	}

	/** Must be called by the authorized sale service before its first save. */
	public static function mark_pos( \WC_Order $order, int $cashier_id ): void {
		if ( $cashier_id < 1 ) {
			throw new \InvalidArgumentException( 'A cashier is required.' );
		}
		$order->set_created_via( 'universal-pos' );
		$order->update_meta_data( self::META_KEY, 'pos' );
		$order->update_meta_data( self::CASHIER_KEY, $cashier_id );
	}
}
