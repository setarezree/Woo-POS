<?php
namespace StarZarei\UniversalPOS\API;

use StarZarei\UniversalPOS\Core\Module;
use StarZarei\UniversalPOS\Inventory\InventoryService;
use StarZarei\UniversalPOS\Orders\OrderService;
use StarZarei\UniversalPOS\Orders\RefundService;
use StarZarei\UniversalPOS\POS\SaleException;
use StarZarei\UniversalPOS\Database\Operations;

defined( 'ABSPATH' ) || exit;

final class ManagementController implements Module {
 public function register(): void {
  add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( OrderService::class, 'legacy_query' ), 10, 2 );
  add_action( 'rest_api_init', function () {
   $routes = array(
    '/management/operations' => array( 'GET', 'lookup' ),
    '/management/operations/abandon' => array( 'POST', 'abandon' ),
    '/management/products/(?P<id>\d+)' => array( 'GET', 'product' ),
    '/management/products' => array( 'GET', 'products' ),
    '/management/stock-history' => array( 'GET', 'history' ),
    '/management/products/(?P<id>\d+)/stock' => array( 'POST', 'stock' ),
    '/management/orders' => array( 'GET', 'orders' ),
    '/management/orders/(?P<id>\d+)' => array( 'GET', 'order' ),
    '/management/orders/(?P<id>\d+)/notes' => array( 'POST', 'note' ),
    '/management/orders/(?P<id>\d+)/status' => array( 'POST', 'status' ),
    '/management/orders/(?P<id>\d+)/refund-quote' => array( 'POST', 'quote' ),
    '/management/orders/(?P<id>\d+)/refunds' => array( 'POST', 'refund' ),
   );
   foreach ( $routes as $route => [ $method, $operation ] ) {
    register_rest_route( 'universal-pos/v1', $route, array( 'methods' => $method, 'permission_callback' => static function () use ( $operation ) {
     try { if ( in_array( $operation, array( 'lookup', 'abandon' ), true ) ) { if ( ! is_user_logged_in() ) { Operations::denied(); } } elseif ( in_array( $operation, array( 'products', 'product', 'history', 'stock' ), true ) ) { InventoryService::authorize(); } else { OrderService::authorize( 0, in_array( $operation, array( 'quote', 'refund' ), true ) ); } return true; }
     catch ( SaleException $error ) { return new \WP_Error( $error->error_code, $error->getMessage(), array( 'status' => $error->status ) ); }
    }, 'callback' => fn( $request ) => $this->dispatch( $operation, $request ) ) );
   }
  } );
 }
 private function dispatch( string $operation, \WP_REST_Request $request ) {
  try {
   $inventory = new InventoryService(); $orders = new OrderService(); $refunds = new RefundService();
   $body = $request->get_json_params();
   if ( 'POST' === $request->get_method() && ( ! is_array( $body ) || array_is_list( $body ) ) ) { Operations::invalid(); }
   $id = (int) $request['id']; $key = (string) $request->get_header( 'Idempotency-Key' );
   $data = match ( $operation ) {
    'lookup' => Operations::lookup( (string) $request->get_param( 'kind' ), (string) $request->get_param( 'key' ) ),
    'abandon' => Operations::lookup( is_string( $body['kind'] ?? null ) ? $body['kind'] : '', is_string( $body['key'] ?? null ) ? $body['key'] : '', true ),
    'product' => $inventory->detail( $id ),
    'products' => $inventory->products( $request->get_query_params() ),
    'history' => $inventory->history( $request->get_query_params() ),
    'stock' => $inventory->adjust( $id, $body, $key ),
    'orders' => $orders->listing( $request->get_query_params() ),
    'order' => $orders->detail( $id ),
    'note', 'status' => $orders->mutate( $id, $operation, $body, $key ),
    'quote' => $refunds->quote( $id, $body ),
    'refund' => $refunds->create( $id, $body, $key ),
   };
   $response = new \WP_REST_Response( $data ); $response->header( 'Cache-Control', 'no-store, private' ); return $response;
  } catch ( SaleException $error ) { return new \WP_Error( $error->error_code, $error->getMessage(), array( 'status' => $error->status ) ); }
  catch ( \Throwable $error ) {
   wc_get_logger()->error( 'Management operation failed: ' . get_class( $error ), array( 'source' => 'universal-woocommerce-pos' ) );
   return new \WP_Error( 'upos_management_failed', __( 'The operation failed. Check its recorded result before retrying the same request.', 'universal-woocommerce-pos' ), array( 'status' => 503 ) );
  }
 }
}
