<?php
namespace StarZarei\UniversalPOS\Payments;

use StarZarei\UniversalPOS\POS\SaleException;

defined( 'ABSPATH' ) || exit;

/** Monetary comparison uses integer minor units; WooCommerce owns tax/price calculation. */
final class Money {
	public static function minor( string $amount ): int {
		$decimals = wc_get_price_decimals();
		if ( $decimals < 0 || $decimals > 8 || ! preg_match( '/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,8}))?$/D', $amount, $parts ) ) {
			throw new SaleException( 'upos_invalid_amount', __( 'Enter a valid decimal amount without separators.', 'universal-woocommerce-pos' ) );
		}
		$fraction = $parts[2] ?? '';
		if ( strlen( $fraction ) > $decimals ) {
			throw new SaleException( 'upos_invalid_precision', __( 'The amount has more decimal places than the store currency allows.', 'universal-woocommerce-pos' ) );
		}
		$digits = ltrim( $parts[1] . str_pad( $fraction, $decimals, '0' ), '0' );
		if ( strlen( $digits ) > 15 ) {
			throw new SaleException( 'upos_amount_too_large', __( 'The amount exceeds the supported limit.', 'universal-woocommerce-pos' ) );
		}
		return (int) $digits;
	}
}
