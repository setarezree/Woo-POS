<?php
namespace StarZarei\UniversalPOS\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {
	public const VERSION = '4';
	public const TABLES = array( 'payment_lines', 'financial_transactions', 'stock_movements', 'audit_logs', 'cash_registers', 'sale_requests', 'parked_carts', 'operation_requests' );

	public static function table( string $name ): string {
		global $wpdb;
		if ( ! in_array( $name, self::TABLES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown Universal POS table.' );
		}
		return $wpdb->prefix . 'upos_' . $name;
	}

	public static function migrate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		// Logical references only: WooCommerce owns product/order storage, including HPOS.
		$definitions = array(
			'operation_requests' => "request_key varchar(64) NOT NULL,
				payload_hash varchar(64) NOT NULL,
				kind varchar(64) NOT NULL,
				result_ids longtext NOT NULL,
				created_by bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (request_key),
				KEY actor_date (created_by,created_at)",
			'parked_carts' => "key_hash varchar(64) NOT NULL,
				cart_key varchar(128) NOT NULL,
				request_hash varchar(64) NOT NULL,
				label varchar(100) NOT NULL DEFAULT '',
				payload longtext NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'parked',
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_by bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				expires_at datetime NOT NULL,
				PRIMARY KEY  (key_hash),
				KEY owner_status (created_by,status),
				KEY expires_at (expires_at)",
			'sale_requests' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				request_key varchar(64) NOT NULL,
				payload_hash varchar(64) NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				created_by bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY request_key (request_key),
				KEY order_id (order_id)",
			'cash_registers' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status)",
			'payment_lines' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				idempotency_key varchar(64) NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				register_id bigint(20) unsigned DEFAULT NULL,
				amount decimal(26,8) NOT NULL,
				currency varchar(10) NOT NULL,
				method varchar(64) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				reference varchar(191) NOT NULL DEFAULT '',
				notes text DEFAULT NULL,
				created_by bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY order_id (order_id),
				KEY register_date (register_id,created_at),
				KEY cashier_date (created_by,created_at)",
			'financial_transactions' => "refund_id bigint(20) unsigned DEFAULT NULL,
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				idempotency_key varchar(64) NOT NULL,
				type varchar(40) NOT NULL,
				amount decimal(26,8) NOT NULL,
				currency varchar(10) NOT NULL,
				method varchar(64) NOT NULL,
				order_id bigint(20) unsigned DEFAULT NULL,
				payment_line_id bigint(20) unsigned DEFAULT NULL,
				register_id bigint(20) unsigned DEFAULT NULL,
				reference varchar(191) NOT NULL DEFAULT '',
				notes text DEFAULT NULL,
				created_by bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY order_id (order_id),
				KEY payment_line_id (payment_line_id),
				KEY register_date (register_id,created_at),
				KEY type_date (type,created_at)",
			'stock_movements' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_key varchar(64) NOT NULL,
				product_id bigint(20) unsigned NOT NULL,
				variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
				quantity_before decimal(26,8) DEFAULT NULL,
				quantity_after decimal(26,8) DEFAULT NULL,
				quantity_change decimal(26,8) NOT NULL,
				type varchar(40) NOT NULL,
				order_id bigint(20) unsigned DEFAULT NULL,
				notes text DEFAULT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_key (event_key),
				KEY product_date (product_id,created_at),
				KEY variation_id (variation_id),
				KEY order_id (order_id)",
			'audit_logs' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				action varchar(100) NOT NULL,
				object_type varchar(40) NOT NULL,
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				order_id bigint(20) unsigned DEFAULT NULL,
				context longtext DEFAULT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY object_lookup (object_type,object_id),
				KEY order_id (order_id),
				KEY actor_date (created_by,created_at),
				KEY action_date (action,created_at)",
		);
		foreach ( $definitions as $name => $columns ) {
			$table = self::table( $name );
			dbDelta( "CREATE TABLE $table (\n$columns\n) ENGINE=InnoDB $charset;" );
			if ( $wpdb->last_error || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				throw new \RuntimeException( 'Universal POS schema migration failed.' );
			}
			// dbDelta can execute several statements; a later success must not hide a failed ALTER.
			$actual_columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), 0 );
			preg_match_all( '/^\s*([a-z_]+)\s+(?:bigint|varchar|decimal|datetime|text|longtext)\b/m', $columns, $expected_columns );
			if ( array_diff( $expected_columns[1], $actual_columns ) ) {
				throw new \RuntimeException( 'Universal POS schema has missing columns.' );
			}
			$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ) );
			$unique_keys = array();
			foreach ( $indexes as $index ) {
				if ( ! (int) $index->Non_unique ) {
					$unique_keys[] = $index->Key_name;
				}
			}
			preg_match_all( '/UNIQUE KEY ([a-z_]+)/', $columns, $expected_keys );
			if ( array_diff( array_merge( array( 'PRIMARY' ), $expected_keys[1] ), $unique_keys ) ) {
				throw new \RuntimeException( 'Universal POS schema has missing unique indexes.' );
			}
		}
		// Only advance the version after every table has been successfully created/upgraded.
		update_option( 'upos_db_version', self::VERSION, false );
	}
}
