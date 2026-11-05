<?php
namespace StarZarei\UniversalPOS\Products;

use StarZarei\UniversalPOS\Core\Module;
use StarZarei\UniversalPOS\Database\AtomicOperation;
use StarZarei\UniversalPOS\POS\SaleException;

defined( 'ABSPATH' ) || exit;

final class Barcode implements Module {
	public function register(): void {
		add_filter( 'woocommerce_product_data_store_cpt_get_products_query', static function ( $query, $vars ) {
			if ( isset( $vars['upos_barcode'] ) ) { $query['meta_query'][] = array( 'key' => self::meta_key(), 'value' => $vars['upos_barcode'], 'compare' => '=', 'type' => 'BINARY' ); }
			return $query;
		}, 10, 2 );
		add_action( 'woocommerce_product_options_inventory_product_data', static function () {
			global $product_object;
			woocommerce_wp_text_input( array( 'id' => 'upos_barcode', 'label' => __( 'POS barcode', 'universal-woocommerce-pos' ), 'value' => $product_object ? $product_object->get_meta( self::meta_key(), true ) : '', 'desc_tip' => true, 'description' => __( 'Unique code used by the POS scanner; leading zeros are preserved.', 'universal-woocommerce-pos' ) ) );
		} );
		add_action( 'woocommerce_product_after_variable_attributes', static function ( $loop, $data, $variation ) {
			$product = wc_get_product( $variation->ID );
			woocommerce_wp_text_input( array( 'id' => 'upos_variation_barcode_' . $loop, 'name' => 'upos_variation_barcode[' . $loop . ']', 'label' => __( 'POS barcode', 'universal-woocommerce-pos' ), 'value' => $product ? $product->get_meta( self::meta_key(), true ) : '', 'wrapper_class' => 'form-row form-row-full' ) );
		}, 10, 3 );
		// These actions run after WooCommerce has verified its product/variation edit nonces.
		add_action( 'woocommerce_process_product_meta', static function ( $id ) { if ( isset( $_POST['upos_barcode'] ) ) { self::from_form( (int) $id, $_POST['upos_barcode'] ); } }, 30 );
		add_action( 'woocommerce_save_product_variation', static function ( $id, $loop ) { if ( isset( $_POST['upos_variation_barcode'][ $loop ] ) ) { self::from_form( (int) $id, $_POST['upos_variation_barcode'][ $loop ] ); } }, 30, 2 );
		add_action( 'admin_init', static function () {
			register_setting( 'upos_barcode', 'upos_barcode_meta_key', array( 'type' => 'string', 'sanitize_callback' => static function ( $key ) {
				try { return self::validated_key( $key ); }
				catch ( SaleException $error ) { add_settings_error( 'upos_barcode_meta_key', 'invalid', $error->getMessage() ); return self::meta_key(); }
			} ) );
		} );
		add_filter( 'option_page_capability_upos_barcode', static fn() => 'upos_manage_settings' );
		add_action( 'admin_menu', static function () {
			add_submenu_page( 'universal-pos', __( 'Barcode settings', 'universal-woocommerce-pos' ), __( 'Barcode settings', 'universal-woocommerce-pos' ), 'upos_manage_settings', 'upos-barcode-settings', static function () {
				if ( ! current_user_can( 'upos_manage_settings' ) ) { return; }
				require UPOS_PATH . 'templates/admin/barcode-settings.php';
			} );
		} );
	}

	public static function validated_key( $key ): string {
		$store = \WC_Data_Store::load( 'product' );
		$core = is_callable( array( $store, 'get_internal_meta_keys' ) ) ? $store->get_internal_meta_keys() : array( '_sku', '_price', '_stock', '_regular_price', '_sale_price', '_manage_stock', '_thumbnail_id', '_product_attributes' );
		if ( ! is_string( $key ) || ! preg_match( '/^_[A-Za-z][A-Za-z0-9_]{1,62}$/D', $key ) || str_starts_with( $key, '_wp_' ) || in_array( $key, array( '_edit_lock', '_edit_last', '_encloseme', '_pingme' ), true ) || in_array( $key, $core, true ) ) {
			throw new SaleException( 'upos_barcode_meta_invalid', __( 'Choose a custom metadata key, not an internal WordPress or WooCommerce field.', 'universal-woocommerce-pos' ) );
		}
		return $key;
	}

	public static function meta_key(): string {
		try { return self::validated_key( get_option( 'upos_barcode_meta_key', '_upos_barcode' ) ); }
		catch ( SaleException $error ) { return '_upos_barcode'; }
	}

	public static function code( string $value ): string {
		$value = trim( $value );
		if ( '' !== $value && ! preg_match( '~^[A-Za-z0-9][A-Za-z0-9._:+/\-]{0,63}$~D', $value ) ) {
			throw new SaleException( 'upos_barcode_invalid', __( 'Use a barcode of up to 64 ASCII letters, digits or . _ : + / - characters.', 'universal-woocommerce-pos' ) );
		}
		return $value;
	}

	public static function matches( string $code, bool $published = true ): array {
		$args = array( 'upos_barcode' => $code, 'status' => $published ? 'publish' : array( 'publish', 'draft', 'pending', 'private', 'future' ), 'limit' => 2, 'return' => 'ids' );
		$ids = array_merge( wc_get_products( $args ), wc_get_products( array_merge( $args, array( 'type' => 'variation' ) ) ) );
		$sku = wc_get_product_id_by_sku( $code ); if ( $sku ) { $ids[] = $sku; }
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	public static function assign( int $id, string $code ): void {
		if ( ! current_user_can( 'edit_product', $id ) ) { throw new SaleException( 'upos_forbidden', __( 'You do not have access to this operation.', 'universal-woocommerce-pos' ), 403 ); }
		$code = self::code( $code );
		AtomicOperation::run( array( 'barcode-product:' . $id, 'barcode-code:' . $code ), static function () use ( $id, $code ) {
			$product = wc_get_product( $id );
			if ( ! $product ) { throw new SaleException( 'upos_not_found', __( 'The requested item was not found.', 'universal-woocommerce-pos' ), 404 ); }
			if ( '' !== $code && array_diff( self::matches( $code, false ), array( $id ) ) ) {
				throw new SaleException( 'upos_barcode_duplicate', __( 'This code already belongs to another barcode or SKU.', 'universal-woocommerce-pos' ), 409 );
			}
			if ( '' === $code ) { $product->delete_meta_data( self::meta_key() ); }
			else { $product->update_meta_data( self::meta_key(), $code ); }
			$product->save_meta_data();
		} );
	}

	private static function from_form( int $id, $value ): void {
		try {
			if ( ! is_string( $value ) ) { throw new SaleException( 'upos_barcode_invalid', __( 'Invalid barcode value.', 'universal-woocommerce-pos' ) ); }
			self::assign( $id, wp_unslash( $value ) );
		} catch ( SaleException $error ) { \WC_Admin_Meta_Boxes::add_error( $error->getMessage() ); }
	}
}
