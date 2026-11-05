<?php
/**
 * Plugin Name: Universal WooCommerce POS & Store Manager
 * Description: Online POS sales and manually confirmed split payments for WooCommerce.
 * Version: 0.4.0
 * Author: ستاره زارعی
 * Text Domain: universal-woocommerce-pos
 * Domain Path: /languages
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'UPOS_VERSION', '0.4.0' );
define( 'UPOS_FILE', __FILE__ );
define( 'UPOS_PATH', plugin_dir_path( __FILE__ ) );

spl_autoload_register( static function ( $class ) {
	$prefix = 'StarZarei\\UniversalPOS\\';
	if ( 0 !== strpos( $class, $prefix ) ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
		return;
	}
	$file = UPOS_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );

register_activation_hook( __FILE__, array( StarZarei\UniversalPOS\Core\Installer::class, 'activate' ) );
add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', UPOS_FILE, true );
	}
} );
add_action( 'plugins_loaded', array( StarZarei\UniversalPOS\Core\Plugin::class, 'boot' ), 20 );
