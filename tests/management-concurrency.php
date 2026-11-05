<?php
/** Two independent PHP processes compete through real WooCommerce reservations/locks. */
ob_start();
$site = $argv[1] ?? '';
if ( ! is_file( $site . '/wp-load.php' ) ) { throw new RuntimeException( 'A disposable WordPress path is required.' ); }
require $site . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ! defined( 'UPOS_INTEGRATION_TESTS' ) || ! UPOS_INTEGRATION_TESTS ) { throw new RuntimeException( 'Disposable local site required.' ); }

use StarZarei\UniversalPOS\Database\Schema;
use Automattic\WooCommerce\Checkout\Helpers\ReserveStock;

add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'woocommerce_email_content_type', static fn() => 'text/plain' );

function execute_request( array $job ): array {
	wp_set_current_user( $job['actor'] );
	$r = new WP_REST_Request( 'POST', '/universal-pos/v1' . $job['route'] );
	$r->set_header( 'Content-Type', 'application/json' );
	$r->set_header( 'Idempotency-Key', $job['key'] );
	$r->set_body( wp_json_encode( $job['body'] ) );
	$response = rest_get_server()->dispatch( $r );
	return array( 'status' => $response->get_status(), 'data' => $response->get_data() );
}

if ( '--worker' === ( $argv[2] ?? '' ) ) {
	$file = $argv[3];
	$job = json_decode( file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
	file_put_contents( $file . '.ready', '1' );
	$deadline = microtime( true ) + 20;
	while ( ! is_file( $file . '.go' ) ) {
		if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'Worker barrier timed out.' ); }
		usleep( 10000 );
	}
	$result = execute_request( $job );
	ob_clean();
	echo wp_json_encode( $result );
	exit;
}

$checks = 0;
function assert_race( bool $value, string $label ): void {
	global $checks;
	if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$checks; echo "PASS: $label\n";
}
function race( array $jobs ): array {
	global $site;
	$workers = array(); $files = array();
	try {
		foreach ( $jobs as $job ) {
			$file = tempnam( sys_get_temp_dir(), 'upos-race-' ); $files[] = $file;
			file_put_contents( $file, wp_json_encode( $job ) );
			$command = array( PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, $site, '--worker', $file );
			$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot start worker.' ); }
			fclose( $pipes[0] );
			$workers[] = array( $process, $pipes, $file );
		}
		$deadline = microtime( true ) + 20;
		foreach ( $workers as [ $process, $pipes, $file ] ) {
			while ( ! is_file( $file . '.ready' ) ) {
				if ( microtime( true ) > $deadline || ! proc_get_status( $process )['running'] ) { throw new RuntimeException( 'Workers failed to reach barrier.' ); }
				usleep( 10000 );
			}
		}
		foreach ( $files as $file ) { file_put_contents( $file . '.go', '1' ); }
		$results = array();
		foreach ( $workers as [ $process, $pipes ] ) {
			$out = stream_get_contents( $pipes[1] ); $error = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] ); fclose( $pipes[2] );
			$exit = proc_close( $process );
			if ( 0 !== $exit || '' !== $error ) { throw new RuntimeException( "Worker failed ($exit): $error $out" ); }
			$results[] = json_decode( $out, true, 512, JSON_THROW_ON_ERROR );
		}
		$workers = array();
		return $results;
	} finally {
		foreach ( $workers as [ $process ] ) { if ( is_resource( $process ) ) { proc_terminate( $process ); proc_close( $process ); } }
		foreach ( $files as $file ) { foreach ( array( '', '.ready', '.go' ) as $suffix ) { if ( is_file( $file . $suffix ) ) { unlink( $file . $suffix ); } } }
	}
}


$admin = get_user_by( 'login', 'upos_test_admin' );
$originals = array(); foreach ( array( 'woocommerce_custom_orders_table_enabled', 'woocommerce_custom_orders_table_data_sync_enabled', 'woocommerce_manage_stock', 'woocommerce_calc_taxes', 'woocommerce_price_num_decimals' ) as $name ) { $originals[$name] = get_option($name,null); }
$products = array(); $orders = array();
try {
 wp_set_current_user($admin->ID); update_option('woocommerce_custom_orders_table_data_sync_enabled','no');update_option('woocommerce_manage_stock','yes');update_option('woocommerce_calc_taxes','no');update_option('woocommerce_price_num_decimals','2');
 foreach(array('no','yes') as $hpos){
  update_option('woocommerce_custom_orders_table_enabled',$hpos);
  $p=new WC_Product_Simple();$p->set_name('Management concurrency fixture');$p->set_regular_price('10.00');$p->set_manage_stock(true);$p->set_stock_quantity(10);$id=$p->save();$products[]=$id;
  $adjust=array('type'=>'damage','quantity'=>2,'expected_stock'=>10,'reason'=>'Race actual damaged goods');
  $jobs=array();foreach(array('one','two') as $suffix)$jobs[]=array('actor'=>$admin->ID,'route'=>'/management/products/'.$id.'/stock','key'=>'mg-stock-'.$suffix.'-'.wp_generate_uuid4(),'body'=>$adjust);
  $r=race($jobs);$statuses=array_column($r,'status');sort($statuses);wp_cache_flush();
  assert_race(array(200,409)===$statuses && 8===wc_get_product($id)->get_stock_quantity(),'simultaneous stock adjustments use expected count and mutate once');
  $order=wc_create_order();$orders[]=$order->get_id();$order->add_product(wc_get_product($id),2);$order->calculate_totals();$order->save();$order->payment_complete();$oid=$order->get_id();
  $base=array('mode'=>'partial','items'=>array(array('item_id'=>(int)array_key_first($order->get_items()),'quantity'=>1,'condition'=>'saleable')),'reason'=>'Concurrent actual returned goods');
  $quote=(new \StarZarei\UniversalPOS\Orders\RefundService())->quote($oid,$base);
  $body=array_merge($base,array('quote_hash'=>$quote['quote_hash'],'expected_amount'=>$quote['amount'],'received'=>true,'method'=>'cash','reference'=>''));
  $jobs=array();foreach(array('one','two')as $suffix)$jobs[]=array('actor'=>$admin->ID,'route'=>'/management/orders/'.$oid.'/refunds','key'=>'mg-refund-'.$suffix.'-'.wp_generate_uuid4(),'body'=>$body);
  $r=race($jobs);$statuses=array_column($r,'status');sort($statuses);wp_cache_flush();
  assert_race(array(200,409)===$statuses && 1===count(wc_get_order($oid)->get_refunds()),'different simultaneous refund keys cannot reuse the same quote');
  assert_race(7===wc_get_product($id)->get_stock_quantity(),'competing refunds restore only one physical unit');
  $quote=(new \StarZarei\UniversalPOS\Orders\RefundService())->quote($oid,$base);$body['quote_hash']=$quote['quote_hash'];$shared='mg-refund-shared-'.wp_generate_uuid4();$job=array('actor'=>$admin->ID,'route'=>'/management/orders/'.$oid.'/refunds','key'=>$shared,'body'=>$body);
  $r=race(array($job,$job));wp_cache_flush();
  assert_race(array(200,200)===array_column($r,'status') && $r[0]['data']['refund_id']===$r[1]['data']['refund_id'],'same simultaneous refund request returns one committed native refund');
  assert_race(8===wc_get_product($id)->get_stock_quantity() && '20.00'===wc_format_decimal(wc_get_order($oid)->get_total_refunded(),2),'refund retry reconciles original quantity and money exactly once');
  $p=wc_get_product($id);wc_update_product_stock($p,1,'set');
  $sale=array('items'=>array(array('product_id'=>$id,'quantity'=>1)),'billing'=>array('first_name'=>'Race','last_name'=>'Customer','phone'=>'09123456789'));
  $jobs=array(array('actor'=>$admin->ID,'route'=>'/sales','key'=>'mg-reserve-'.wp_generate_uuid4(),'body'=>$sale),array('actor'=>$admin->ID,'route'=>'/management/products/'.$id.'/stock','key'=>'mg-remove-'.wp_generate_uuid4(),'body'=>array('type'=>'damage','quantity'=>1,'expected_stock'=>1,'reason'=>'Last unit damaged')));
  $r=race($jobs);$statuses=array_column($r,'status');sort($statuses);wp_cache_flush();if(200===$r[0]['status'])$orders[]=$r[0]['data']['id'];
  assert_race(array(200,409)===$statuses,'native reservation and inventory removal cannot consume the last unit together');
  foreach($orders as $cleanup){$o=wc_get_order($cleanup);if($o){as_unschedule_all_actions('upos_expire_sale',array('order_id'=>$cleanup),'universal-pos');(new ReserveStock())->release_stock_for_order($o);foreach($o->get_refunds()as $refund)$refund->delete(true);$o->delete(true);}foreach(array('financial_transactions','payment_lines','stock_movements','audit_logs','sale_requests')as$table)$wpdb->delete(Schema::table($table),array('order_id'=>$cleanup));}$orders=array();
 }
 echo "Completed $checks management concurrency checks.\n";
}finally{
 foreach($orders as$id){$o=wc_get_order($id);if($o){foreach($o->get_refunds()as$r)$r->delete(true);$o->delete(true);}foreach(array('financial_transactions','payment_lines','stock_movements','audit_logs','sale_requests')as$table)$wpdb->delete(Schema::table($table),array('order_id'=>$id));}
 foreach($products as$id){$p=wc_get_product($id);if($p)$p->delete(true);$wpdb->delete(Schema::table('stock_movements'),array('product_id'=>$id));$wpdb->delete(Schema::table('audit_logs'),array('object_type'=>'product','object_id'=>$id));}
 $wpdb->delete(Schema::table('operation_requests'),array('created_by'=>$admin->ID));
 foreach($originals as$name=>$value){null===$value?delete_option($name):update_option($name,$value);}
}
