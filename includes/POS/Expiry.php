<?php
namespace StarZarei\UniversalPOS\POS;

use StarZarei\UniversalPOS\Core\Module;

defined( 'ABSPATH' ) || exit;

final class Expiry implements Module {
	public function register(): void {
		add_action( 'upos_expire_sale', static function ( $order_id ) { ( new SaleService() )->expire( (int) $order_id ); } );
	}

	public static function schedule( \WC_Order $order ): void {
		// The sale transaction and request key already schedule each new order once.
		// AS uniqueness is scoped to hook/group, so it would block other orders' expiry jobs.
		if ( ! function_exists( 'as_schedule_single_action' ) || ! as_schedule_single_action( (int) $order->get_meta( '_upos_expires_at', true ), 'upos_expire_sale', array( 'order_id' => $order->get_id() ), 'universal-pos', false ) ) {
			throw new \RuntimeException( 'Cannot schedule POS sale expiry.' );
		}
	}
}
