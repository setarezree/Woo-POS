<?php
namespace StarZarei\UniversalPOS\POS;

defined( 'ABSPATH' ) || exit;

/** The standalone cashier defaults to Persian even when WordPress language packs are absent. */
final class Strings {
	private static ?\MO $catalog = null;

	public static function locale(): string {
		return 'en_US' === get_option( 'upos_ui_language', 'fa_IR' ) ? 'en_US' : 'fa_IR';
	}

	public static function t( string $text ): string {
		if ( 'en_US' === self::locale() ) { return $text; }
		return self::mo()->translate( $text );
	}

	public static function catalog(): array {
		if ( 'en_US' === self::locale() ) { return array(); }
		$result = array();
		foreach ( self::mo()->entries as $entry ) { $result[ $entry->singular ] = $entry->translations[0]; }
		return $result;
	}

	private static function mo(): \MO {
		if ( null === self::$catalog ) {
			require_once ABSPATH . WPINC . '/pomo/mo.php';
			self::$catalog = new \MO();
			self::$catalog->import_from_file( UPOS_PATH . 'languages/universal-woocommerce-pos-fa_IR.mo' );
		}
		return self::$catalog;
	}
}
