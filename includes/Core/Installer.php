<?php
namespace StarZarei\UniversalPOS\Core;

use StarZarei\UniversalPOS\Database\Schema;
use StarZarei\UniversalPOS\Users\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Installer {
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate Universal POS separately on each site. Network activation is not supported.', 'universal-woocommerce-pos' ) );
		}
		if ( ! Plugin::compatible() ) {
			wp_die( esc_html__( 'Activate WooCommerce 9.0 or later before activating Universal POS.', 'universal-woocommerce-pos' ) );
		}
		self::upgrade();
	}

	public static function upgrade(): void {
		if ( Schema::VERSION !== get_option( 'upos_db_version' ) ) {
			Schema::migrate();
		}
		if ( UPOS_VERSION !== get_option( 'upos_version' ) ) {
			Capabilities::install();
			update_option( 'upos_version', UPOS_VERSION, false );
		}
	}
}
