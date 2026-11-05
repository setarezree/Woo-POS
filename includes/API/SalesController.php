<?php
namespace StarZarei\UniversalPOS\API;

use StarZarei\UniversalPOS\Core\Module;
use StarZarei\UniversalPOS\POS\SaleException;
use StarZarei\UniversalPOS\POS\SaleService;
use StarZarei\UniversalPOS\Payments\ManualPayments;

defined( 'ABSPATH' ) || exit;

final class SalesController implements Module {
	public function register(): void { add_action( 'rest_api_init', array( $this, 'routes' ) ); }

	public function routes(): void {
		$permission = function () {
			$controller = new StoreController();
			$access = $controller->permission( 'upos_access_pos' );
			return is_wp_error( $access ) ? $access : $controller->permission( 'upos_create_sales' );
		};
		foreach ( array(
			'/sales' => array( 'POST', 'prepare' ),
			'/sales/recent' => array( 'GET', 'recent' ),
			'/sales/lookup' => array( 'GET', 'lookup' ),
			'/sales/abandon' => array( 'POST', 'abandon' ),
			'/sales/(?P<id>\d+)' => array( 'GET', 'get' ),
			'/sales/(?P<id>\d+)/payments' => array( 'POST', 'pay' ),
			'/sales/(?P<id>\d+)/cancel' => array( 'POST', 'cancel' ),
			'/payment-methods' => array( 'GET', 'methods' ),
			'/carts' => array( 'GET', 'carts' ),
		) as $route => [ $method, $operation ] ) {
			register_rest_route( 'universal-pos/v1', $route, array(
				'methods' => $method, 'permission_callback' => $permission,
				'args' => str_contains( $route, '(?P<id>' ) ? array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ) : array(),
				'callback' => fn( $request ) => $this->dispatch( $operation, $request ),
			) );
		}
		register_rest_route( 'universal-pos/v1', '/carts/(?P<key>[A-Za-z0-9._:-]{8,128})', array(
			'permission_callback' => $permission,
			'methods' => 'GET,POST,DELETE',
			'callback' => fn( $request ) => $this->dispatch( match ( $request->get_method() ) { 'POST' => 'save_cart', 'DELETE' => 'delete_cart', default => 'get_cart' }, $request ),
		) );
	}

	private function dispatch( string $operation, \WP_REST_Request $request ) {
		try {
			$service = new SaleService();
			$body = $request->get_json_params();
			if ( in_array( $operation, array( 'prepare', 'pay' ), true ) && ! is_array( $body ) ) {
				throw new SaleException( 'upos_json_required', __( 'Send a JSON object as the request body.', 'universal-woocommerce-pos' ) );
			}
			$data = match ( $operation ) {
				'prepare' => $service->prepare( $body, (string) $request->get_header( 'Idempotency-Key' ) ),
				'pay' => $service->pay( (int) $request['id'], $body, (string) $request->get_header( 'Idempotency-Key' ) ),
				'cancel' => $service->cancel( (int) $request['id'] ),
				'get' => $service->get( (int) $request['id'] ),
				'recent' => $service->recent(),
				'lookup' => $service->lookup( (string) $request->get_param( 'key' ) ),
				'abandon' => $service->lookup( is_array( $body ) && is_string( $body['key'] ?? null ) ? $body['key'] : '', true ),
				'methods' => array( 'methods' => ManualPayments::methods(), 'manual_confirmation_required' => true ),
				'carts' => \StarZarei\UniversalPOS\POS\ParkedCarts::listing(),
				'save_cart' => \StarZarei\UniversalPOS\POS\ParkedCarts::save( $request['key'], is_array( $body ) ? $body : array() ),
				'get_cart' => \StarZarei\UniversalPOS\POS\ParkedCarts::restore( $request['key'] ),
				'delete_cart' => \StarZarei\UniversalPOS\POS\ParkedCarts::discard( $request['key'] ),
			};
			$response = new \WP_REST_Response( $data );
			$response->header( 'Cache-Control', 'no-store, private' );
			return $response;
		} catch ( SaleException $error ) {
			return new \WP_Error( $error->error_code, $error->getMessage(), array( 'status' => $error->status ) );
		} catch ( \Throwable $error ) {
			wc_get_logger()->error( 'POS operation failed: ' . get_class( $error ), array( 'source' => 'universal-woocommerce-pos' ) );
			return new \WP_Error( 'upos_operation_failed', __( 'The operation could not be completed. Check the sale and retry with the same idempotency key.', 'universal-woocommerce-pos' ), array( 'status' => 503 ) );
		}
	}
}
