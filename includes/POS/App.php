<?php
namespace StarZarei\UniversalPOS\POS;

use StarZarei\UniversalPOS\Core\Module;

defined( 'ABSPATH' ) || exit;

final class App implements Module {
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'route' ), 0 );
		add_action( 'admin_menu', function () {
			$hook = add_menu_page( Strings::t( 'Point of sale' ), Strings::t( 'Point of sale' ), 'upos_access_pos', 'universal-pos', '__return_null', 'dashicons-store', 56 );
			add_action( 'load-' . $hook, static function () { wp_safe_redirect( self::url() ); exit; } );
		} );
	}

	public static function url(): string { return add_query_arg( 'upos_app', '1', home_url( '/' ) ); }

	public static function allowed(): bool {
		return is_user_logged_in() && current_user_can( 'upos_access_pos' ) && current_user_can( 'upos_create_sales' ) && current_user_can( 'upos_view_inventory' );
	}

	public function route(): void {
		$is_app = isset( $_GET['upos_app'] ) && '1' === $_GET['upos_app'];
		$is_receipt = isset( $_GET['upos_receipt'] );
		if ( ! $is_app && ! $is_receipt ) { return; }
		if ( ! is_user_logged_in() ) { auth_redirect(); }
		if ( ! self::allowed() ) { wp_die( esc_html( Strings::t( 'You do not have access to this operation.' ) ), '', array( 'response' => 403 ) ); }
		nocache_headers();
		header( 'Cache-Control: no-store, private' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: same-origin' );
		status_header( 200 );
		if ( $is_receipt ) {
			if ( ! is_string( $_GET['upos_receipt'] ) || ! ctype_digit( $_GET['upos_receipt'] ) ) { wp_die( esc_html( Strings::t( 'The requested item was not found.' ) ), '', array( 'response' => 404 ) ); }
			$id = absint( $_GET['upos_receipt'] );
			try {
				$sale = ( new SaleService() )->get( $id );
				$order = wc_get_order( $id );
				if ( ! $order instanceof \WC_Order || ! $order->get_meta( '_upos_payment_key', true ) || ! $order->get_date_paid() ) {
					throw new SaleException( 'upos_receipt_unavailable', Strings::t( 'A receipt is available only after payment has been recorded.' ), 409 );
				}
				$paper = isset( $_GET['paper'] ) && '58' === $_GET['paper'] ? 58 : 80;
				require UPOS_PATH . 'templates/receipts/thermal.php';
			} catch ( SaleException $error ) {
				wp_die( esc_html( Strings::t( $error->getMessage() ) ), '', array( 'response' => $error->status ) );
			}
		} else {
			$config = $this->config();
			require UPOS_PATH . 'templates/pos/app.php';
		}
		exit;
	}

	public function config(): array {
		return array(
			'restUrl' => esc_url_raw( rest_url( 'universal-pos/v1/' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'receiptUrl' => home_url( '/' ),
			'locale' => str_replace( '_', '-', Strings::locale() ),
			'country' => WC()->countries->get_base_country(),
			'state' => WC()->countries->get_base_state(),
			'countries' => WC()->countries->get_countries(),
			'canDiscount' => current_user_can( 'upos_apply_discounts' ),
			'catalog' => Strings::catalog(),
		);
	}

	public static function asset( string $file ): string {
		return add_query_arg( 'ver', UPOS_VERSION, plugins_url( 'assets/' . $file, UPOS_FILE ) );
	}
}
