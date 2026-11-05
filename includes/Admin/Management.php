<?php
namespace StarZarei\UniversalPOS\Admin;

use StarZarei\UniversalPOS\Core\Module;
use StarZarei\UniversalPOS\Inventory\InventoryService;
use StarZarei\UniversalPOS\Orders\OrderService;
use StarZarei\UniversalPOS\POS\Strings;
use StarZarei\UniversalPOS\POS\SaleException;

defined( 'ABSPATH' ) || exit;

final class Management implements Module {
 public function register(): void {
  add_action( 'admin_menu', function () {
   foreach ( array( 'inventory' => array( 'Inventory', 'upos_view_inventory' ), 'orders' => array( 'Orders and returns', 'upos_view_orders' ) ) as $view => [ $title, $cap ] ) {
    if ( ! current_user_can( 'inventory' === $view ? 'edit_products' : 'edit_shop_orders' ) ) { continue; }
    $hook = add_submenu_page( 'universal-pos', Strings::t( $title ), Strings::t( $title ), $cap, 'upos-' . $view, function () use ( $view ) {
     if ( 'inventory' === $view ) { InventoryService::authorize(); } else { OrderService::authorize(); }
     $config = array( 'view' => $view, 'restUrl' => rest_url( 'universal-pos/v1/management/' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'catalog' => Strings::catalog(), 'canStock' => current_user_can( 'upos_adjust_stock' ), 'canRefund' => current_user_can( 'upos_refund_orders' ), 'exportUrl' => html_entity_decode( wp_nonce_url( admin_url( 'admin-post.php?action=upos_stock_export' ), 'upos_stock_export' ), ENT_QUOTES, 'UTF-8' ), 'receiptUrl' => html_entity_decode( wp_nonce_url( admin_url( 'admin-post.php?action=upos_order_receipt' ), 'upos_order_receipt' ), ENT_QUOTES, 'UTF-8' ), 'methods' => \StarZarei\UniversalPOS\Payments\ManualPayments::methods() );
     require UPOS_PATH . 'templates/admin/management.php';
    } );
    add_action( 'load-' . $hook, static function () { nocache_headers(); } );
    add_action( 'admin_print_styles-' . $hook, static function () { wp_enqueue_style( 'upos-management', plugins_url( 'assets/css/management.css', UPOS_FILE ), array(), UPOS_VERSION ); } );
    add_action( 'admin_print_scripts-' . $hook, static function () { wp_enqueue_script( 'upos-management', plugins_url( 'assets/js/management.js', UPOS_FILE ), array(), UPOS_VERSION, true ); } );
   }
  }, 20 );
  add_action( 'admin_post_upos_order_receipt', static function () {
   try {
    check_admin_referer( 'upos_order_receipt' ); $order = OrderService::order( absint( $_GET['order_id'] ?? 0 ) );
    if ( ! $order->get_date_paid() ) { throw new SaleException( 'upos_receipt_unavailable', Strings::t( 'A receipt is available only after payment has been recorded.' ), 409 ); }
    $sale = ( new OrderService() )->detail( $order->get_id() ); $sale['price_decimals'] = '' === $order->get_meta( '_upos_price_decimals', true ) ? wc_get_price_decimals() : (int) $order->get_meta( '_upos_price_decimals', true );
    $sale['manual_discount'] = $order->get_meta( '_upos_manual_discount', true ); $sale['coupon'] = $sale['manual_discount'] ? '' : implode( ', ', $order->get_coupon_codes() );
    $paper = '58' === ( $_GET['paper'] ?? '' ) ? 58 : 80; $management_receipt = true;
    nocache_headers(); header( 'X-Content-Type-Options: nosniff' ); header( 'X-Frame-Options: SAMEORIGIN' ); header( 'Referrer-Policy: same-origin' );
    require UPOS_PATH . 'templates/receipts/thermal.php'; exit;
   } catch ( SaleException $error ) { wp_die( esc_html( Strings::t( $error->getMessage() ) ), '', array( 'response' => $error->status ) ); }
  } );
  add_action( 'admin_post_upos_stock_export', static function () {
   try {
    InventoryService::authorize(); check_admin_referer( 'upos_stock_export' );
    $args = array_intersect_key( wp_unslash( $_GET ), array_flip( array( 'product_id', 'order_id', 'type', 'from', 'to' ) ) );
    $rows = ( new InventoryService() )->history( $args, 5000 )['movements'];
    nocache_headers(); header( 'Content-Type: text/csv; charset=UTF-8' ); header( 'Content-Disposition: attachment; filename="stock-history.csv"' ); header( 'X-Content-Type-Options: nosniff' );
    $stream = fopen( 'php://output', 'w' ); fwrite( $stream, "\xEF\xBB\xBF" );
    $fields = array( 'id', 'product_id', 'variation_id', 'quantity_before', 'quantity_after', 'quantity_change', 'type', 'order_id', 'created_by', 'created_at', 'notes' );
    fputcsv( $stream, $fields, ',', '"', '' );
    foreach ( $rows as $row ) {
     $values = array(); foreach ( $fields as $field ) { $value = (string) $row[ $field ]; if ( preg_match( '/^[\s\x00-\x1f]*[=+@\-]/u', $value ) && ! is_numeric( $value ) ) { $value = "'" . $value; } $values[] = $value; }
     fputcsv( $stream, $values, ',', '"', '' );
    }
    fclose( $stream ); exit;
   } catch ( SaleException $error ) { wp_die( esc_html( Strings::t( $error->getMessage() ) ), '', array( 'response' => $error->status ) ); }
  } );
 }
}
