<?php
namespace StarZarei\UniversalPOS\API;

use StarZarei\UniversalPOS\Core\Module;
use StarZarei\UniversalPOS\Database\Schema;
use StarZarei\UniversalPOS\Orders\SalesChannel;
use StarZarei\UniversalPOS\Products\ProductRepository;

defined( 'ABSPATH' ) || exit;

final class StoreController implements Module {
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		$namespace = 'universal-pos/v1';
		register_rest_route( $namespace, '/store', array(
			'methods' => 'GET', 'callback' => array( $this, 'store' ),
			'permission_callback' => fn() => $this->permission( 'upos_access_pos' ),
		) );
		register_rest_route( $namespace, '/products', array(
			'methods' => 'GET', 'callback' => array( $this, 'products' ),
			'permission_callback' => fn() => $this->permission( 'upos_view_inventory' ),
			'args' => array(
				'page' => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
				'search' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'sku' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'parent' => array( 'type' => 'integer', 'minimum' => 1 ),
			),
		) );
		register_rest_route( $namespace, '/products/(?P<id>\d+)', array(
			'methods' => 'GET', 'callback' => array( $this, 'product' ),
			'permission_callback' => fn() => $this->permission( 'upos_view_inventory' ),
			'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
		) );
		register_rest_route( $namespace, '/products/scan', array(
			'methods' => 'GET',
			'callback' => function ( $request ) {
				$matches = array();
				$ids = \StarZarei\UniversalPOS\Products\Barcode::matches( $request['code'] );
				// Detect collisions before hiding children of unpublished parents: a limited
				// result set must never hide a second saleable match and select arbitrarily.
				if ( count( $ids ) > 1 ) { return new \WP_Error( 'upos_ambiguous_code', __( 'This code matches multiple products. Ask a manager to correct the barcode or SKU.', 'universal-woocommerce-pos' ), array( 'status' => 409 ) ); }
				foreach ( $ids as $id ) {
					$product = wc_get_product( $id );
					if ( ! $product || 'publish' !== $product->get_status() ) { continue; }
					if ( $product->get_parent_id() ) { $parent = wc_get_product( $product->get_parent_id() ); if ( ! $parent || 'publish' !== $parent->get_status() ) { continue; } }
					$matches[] = $product;
				}
				if ( ! $matches ) { return $this->not_found(); }
				$product = $matches[0];
				return $this->response( ( new ProductRepository() )->serialize( $product ) );
			},
			'permission_callback' => fn() => $this->permission( 'upos_view_inventory' ),
			'args' => array( 'code' => array( 'type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 100, 'sanitize_callback' => 'sanitize_text_field' ) ),
		) );
		register_rest_route( $namespace, '/products/batch', array(
			'methods' => 'POST',
			'permission_callback' => fn() => $this->permission( 'upos_view_inventory' ),
			'args' => array( 'ids' => array( 'type' => 'array', 'required' => true, 'minItems' => 1, 'maxItems' => 100, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ) ),
			'callback' => function ( $request ) {
				$repository = new ProductRepository(); $products = array(); $missing = array();
				foreach ( array_unique( $request['ids'] ) as $id ) {
					$product = $repository->find( $id );
					$parent = $product && $product->get_parent_id() ? $repository->find( $product->get_parent_id() ) : null;
					if ( ! $product || 'publish' !== $product->get_status() || ( $product->get_parent_id() && ( ! $parent || 'publish' !== $parent->get_status() ) ) ) { $missing[] = $id; continue; }
					$products[] = $repository->serialize( $product );
				}
				return $this->response( array( 'products' => $products, 'missing' => $missing ) );
			},
		) );
		register_rest_route( $namespace, '/orders/(?P<id>\d+)', array(
			'methods' => 'GET', 'callback' => array( $this, 'order' ),
			'permission_callback' => fn() => $this->permission( 'upos_view_orders' ),
			'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
		) );
	}

	public function permission( string $capability ) {
		// WordPress REST authenticates cookies with X-WP-Nonce and also supports application passwords.
		if ( ! is_user_logged_in() || ! current_user_can( $capability ) ) {
			return new \WP_Error( 'upos_forbidden', __( 'You do not have access to this operation.', 'universal-woocommerce-pos' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	private function response( array $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	public function store(): \WP_REST_Response {
		return $this->response( array(
			'plugin_version' => UPOS_VERSION,
			'database_version' => get_option( 'upos_db_version' ),
			'woocommerce_version' => WC_VERSION,
			'currency' => get_woocommerce_currency(),
			'currency_symbol' => get_woocommerce_currency_symbol(),
			'currency_symbol_text' => html_entity_decode( wp_strip_all_tags( get_woocommerce_currency_symbol() ), ENT_QUOTES, 'UTF-8' ),
			'price_decimals' => wc_get_price_decimals(),
			'decimal_separator' => wc_get_price_decimal_separator(),
			'thousand_separator' => wc_get_price_thousand_separator(),
			'currency_position' => get_option( 'woocommerce_currency_pos', 'left' ),
			'prices_include_tax' => wc_prices_include_tax(),
			'taxes_enabled' => wc_tax_enabled(),
			'hpos_enabled' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
		) );
	}

	public function products( \WP_REST_Request $request ): \WP_REST_Response {
		$repository = new ProductRepository();
		$args = array( 'page' => $request['page'], 'limit' => $request['per_page'] );
		if ( $request['search'] ) {
			$args['s'] = $request['search'];
		}
		if ( $request['sku'] ) {
			$args['sku'] = $request['sku'];
		}
		if ( $request['parent'] ) {
			$parent = $repository->find( (int) $request['parent'] );
			if ( ! $parent || 'publish' !== $parent->get_status() || ! $parent->is_type( 'variable' ) ) {
				return $this->response( array( 'products' => array() ) );
			}
			$args['parent'] = $request['parent'];
			$args['type'] = 'variation';
		}
		$result = $repository->search( $args );
		$response = $this->response( array( 'products' => array_map( array( $repository, 'serialize' ), $result->products ) ) );
		$response->header( 'X-WP-Total', (string) $result->total );
		$response->header( 'X-WP-TotalPages', (string) $result->max_num_pages );
		return $response;
	}

	public function product( \WP_REST_Request $request ) {
		$repository = new ProductRepository();
		$product = $repository->find( (int) $request['id'] );
		if ( ! $product || 'publish' !== $product->get_status() ) {
			return $this->not_found();
		}
		if ( $product->get_parent_id() ) {
			$parent = $repository->find( $product->get_parent_id() );
			if ( ! $parent || 'publish' !== $parent->get_status() ) { return $this->not_found(); }
		}
		return $this->response( $repository->serialize( $product ) );
	}

	public function order( \WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return $this->not_found();
		}
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array( 'product_id' => $item->get_product_id(), 'variation_id' => $item->get_variation_id(), 'quantity' => $item->get_quantity(), 'total' => $item->get_total() );
		}
		return $this->response( array(
			'id' => $order->get_id(), 'number' => $order->get_order_number(),
			'status' => $order->get_status(), 'currency' => $order->get_currency(),
			'total' => $order->get_total(), 'channel' => SalesChannel::get( $order ),
			'cashier_id' => (int) $order->get_meta( SalesChannel::CASHIER_KEY, true ),
			'items' => $items,
		) );
	}

	private function not_found(): \WP_Error {
		return new \WP_Error( 'upos_not_found', __( 'The requested item was not found.', 'universal-woocommerce-pos' ), array( 'status' => 404 ) );
	}
}
