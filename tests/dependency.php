<?php
/** Simulate WooCommerce being disabled using a per-process WordPress option filter. */
$site = $argv[1] ?? '';
if ( ! is_file( $site . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php tests/dependency.php /path/to/disposable/wordpress\n" );
	exit( 1 );
}
$GLOBALS['wp_filter']['option_active_plugins'][10][] = array(
	'function' => static fn( $plugins ) => array_values( array_diff( $plugins, array( 'woocommerce/woocommerce.php' ) ) ),
	'accepted_args' => 1,
);
require $site . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ! defined( 'UPOS_INTEGRATION_TESTS' ) || ! UPOS_INTEGRATION_TESTS ) {
	throw new RuntimeException( 'A disposable local test installation is required.' );
}
if ( class_exists( 'WooCommerce' ) || ! class_exists( 'StarZarei\\UniversalPOS\\Core\\Plugin' ) ) {
	throw new RuntimeException( 'Dependency test did not load the expected plugin configuration.' );
}
$admin = get_user_by( 'login', 'upos_test_admin' );
if ( ! $admin ) { throw new RuntimeException( 'Missing test administrator.' ); }
wp_set_current_user( $admin->ID );
ob_start();
StarZarei\UniversalPOS\Core\Plugin::dependency_notice();
$notice = ob_get_clean();
if ( ! str_contains( $notice, 'WooCommerce 9.0' ) ) {
	throw new RuntimeException( 'Dependency notice missing.' );
}
foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) {
	if ( str_starts_with( $route, '/universal-pos/' ) ) {
		throw new RuntimeException( 'Operational API loaded without WooCommerce.' );
	}
}
echo "PASS: plugin loads safely without WooCommerce, shows dependency notice and registers no operational API.\n";
