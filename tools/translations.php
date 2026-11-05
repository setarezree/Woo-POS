<?php
/** Compile PO catalogs using WordPress's own POMO implementation. */
$site = $argv[1] ?? '';
if ( ! is_file( $site . '/wp-includes/pomo/po.php' ) ) {
	fwrite( STDERR, "Usage: php tools/translations.php /path/to/wordpress\n" );
	exit( 1 );
}
require_once $site . '/wp-includes/pomo/po.php';
require_once $site . '/wp-includes/pomo/mo.php';
foreach ( glob( dirname( __DIR__ ) . '/languages/*.po' ) as $file ) {
	$po = new PO();
	if ( ! $po->import_from_file( $file ) ) {
		throw new RuntimeException( 'Cannot read translation catalog: ' . $file );
	}
	$mo = new MO();
	$mo->entries = $po->entries;
	$mo->headers = $po->headers;
	if ( ! $mo->export_to_file( substr( $file, 0, -3 ) . '.mo' ) ) {
		throw new RuntimeException( 'Cannot write compiled catalog.' );
	}
	echo basename( $file ) . " compiled.\n";
}
