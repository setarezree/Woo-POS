<?php
namespace StarZarei\UniversalPOS\Database;

use StarZarei\UniversalPOS\POS\SaleException;
use StarZarei\UniversalPOS\Payments\DeferredEmails;

defined( 'ABSPATH' ) || exit;

final class AtomicOperation {
	/** Named locks serialize retries/cancel/payment; WooCommerce reservations serialize stock. */
	public static function run( string|array $resource, callable $callback ) {
		global $wpdb;
		$resources = array_unique( (array) $resource ); sort( $resources, SORT_STRING ); $locks = array();
		$started = false;
		$guard = null;
		$committed = false;
		$emails = new DeferredEmails();
		try {
			foreach ( $resources as $name ) {
				$lock = 'upos:' . substr( hash( 'sha256', $wpdb->prefix . ':' . $name ), 0, 59 );
				if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
					throw new SaleException( 'upos_busy', __( 'This sale is being processed. Retry with the same idempotency key.', 'universal-woocommerce-pos' ), 409 );
				}
				$locks[] = $lock;
			}
			// MySQL and MariaDB both reject SET TRANSACTION inside an active transaction.
			// This also avoids relying on MariaDB-specific session state variables.
			if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' ) ) {
				throw new \RuntimeException( 'Cannot configure POS transaction isolation.' );
			}
			self::check_engines();
			$emails->capture();
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'Cannot start database transaction.' );
			}
			$started = true;
			$failure = null;
			// wpdb filters before clearing last_error. Stop even when native code ignores
			// a failed query: an InnoDB deadlock may already have aborted the transaction.
			$guard = static function ( $sql ) use ( $wpdb, &$failure ) {
				if ( null !== $failure ) { throw $failure; }
				if ( $wpdb->last_error ) {
					$number = $wpdb->dbh instanceof \mysqli ? mysqli_errno( $wpdb->dbh ) : 0;
					$failure = in_array( $number, array( 1205, 1213 ), true )
						? new SaleException( 'upos_busy', __( 'This sale is being processed. Retry with the same idempotency key.', 'universal-woocommerce-pos' ), 409 )
						: new \RuntimeException( 'Cannot continue after a database query failure.' );
					throw $failure;
				}
				if ( is_string( $sql ) && preg_match( '/^\s*(?:START\s+TRANSACTION|BEGIN\b|COMMIT\b|ROLLBACK\b|SET\s+(?:SESSION\s+)?AUTOCOMMIT\b|(?:CREATE|ALTER|DROP|TRUNCATE|RENAME)\s|(?:UN)?LOCK\s+TABLES)/i', $sql ) ) {
					$failure = new \RuntimeException( 'A nested transaction or implicit commit is not permitted.' ); throw $failure;
				}
				return $sql;
			};
			add_filter( 'query', $guard, PHP_INT_MIN );
			add_filter( 'query', $guard, PHP_INT_MAX );
			$result = $callback();
			$guard( '' );
			remove_filter( 'query', $guard, PHP_INT_MIN ); remove_filter( 'query', $guard, PHP_INT_MAX ); $guard = null;
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Cannot commit POS database transaction.' );
			}
			$started = false;
			$committed = true;
			return $result;
		} catch ( \Throwable $error ) {
			if ( null !== $guard ) { remove_filter( 'query', $guard, PHP_INT_MIN ); remove_filter( 'query', $guard, PHP_INT_MAX ); $guard = null; }
			if ( $started ) {
				$wpdb->query( 'ROLLBACK' );
				// Rollback does not revert WordPress/WooCommerce in-memory or persistent caches.
				wp_cache_flush();
			}
			throw $error;
		} finally {
			$emails->restore();
			foreach ( array_reverse( $locks ) as $lock ) { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
			if ( $committed ) { $emails->send(); }
		}
	}

	private static function check_engines(): void {
		global $wpdb;
		$names = array( 'posts', 'postmeta', 'comments', 'commentmeta', 'options', 'users', 'usermeta',
			'woocommerce_order_items', 'woocommerce_order_itemmeta', 'wc_product_meta_lookup',
			'wc_orders', 'wc_order_addresses', 'wc_order_operational_data', 'wc_orders_meta',
			'wc_reserved_stock', 'woocommerce_downloadable_product_permissions', 'wc_download_log',
			'wc_order_stats', 'wc_order_product_lookup', 'wc_order_tax_lookup', 'wc_order_coupon_lookup', 'wc_customer_lookup',
			'actionscheduler_actions', 'actionscheduler_claims', 'actionscheduler_groups', 'actionscheduler_logs' );
		$names = array_map( fn( $name ) => $wpdb->prefix . $name, $names );
		foreach ( Schema::TABLES as $table ) { $names[] = Schema::table( $table ); }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		if ( ! $rows ) { throw new \RuntimeException( 'Cannot inspect database engines.' ); }
		foreach ( $rows as $row ) {
			if ( in_array( $row->Name, $names, true ) && 'InnoDB' !== $row->Engine ) {
				throw new SaleException( 'upos_innodb_required', __( 'POS requires InnoDB for its WordPress and WooCommerce tables.', 'universal-woocommerce-pos' ), 503 );
			}
		}
	}
}
