<?php
namespace StarZarei\UniversalPOS\Core;

use StarZarei\UniversalPOS\API\StoreController;
use StarZarei\UniversalPOS\API\SalesController;
use StarZarei\UniversalPOS\Orders\SalesChannel;
use StarZarei\UniversalPOS\POS\Expiry;
use StarZarei\UniversalPOS\POS\App;
use StarZarei\UniversalPOS\POS\ParkedCarts;
use StarZarei\UniversalPOS\Products\Barcode;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static bool $booted = false;

	public static function compatible(): bool {
		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.0', '>=' ) && class_exists( 'WooCommerce' );
	}

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'init', static function () {
			load_plugin_textdomain( 'universal-woocommerce-pos', false, dirname( plugin_basename( UPOS_FILE ) ) . '/languages' );
		} );
		if ( ! self::compatible() ) {
			add_action( 'admin_notices', array( self::class, 'dependency_notice' ) );
			return;
		}
		if ( did_action( 'init' ) ) {
			self::initialize();
		} else {
			add_action( 'init', array( self::class, 'initialize' ), 20 );
		}
	}

	public static function initialize(): void {
		try {
			Installer::upgrade();
		} catch ( \Throwable $error ) {
			wc_get_logger()->error( 'Universal POS database upgrade failed.', array( 'source' => 'universal-woocommerce-pos' ) );
			add_action( 'admin_notices', static function () {
				if ( current_user_can( 'activate_plugins' ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Universal POS could not upgrade its database. Check the database permissions and reactivate the plugin.', 'universal-woocommerce-pos' ) . '</p></div>';
				}
			} );
			return;
		}
		$modules = apply_filters( 'upos_modules', array( new SalesChannel(), new StoreController(), new SalesController(), new Expiry(), new App(), new ParkedCarts(), new Barcode() ) );
		foreach ( $modules as $module ) {
			if ( $module instanceof Module ) {
				$module->register();
			}
		}
		do_action( 'upos_loaded' );
	}

	public static function dependency_notice(): void {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Universal POS requires WooCommerce 9.0 or later to be active.', 'universal-woocommerce-pos' ) . '</p></div>';
		}
	}
}
