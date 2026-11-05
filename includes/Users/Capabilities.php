<?php
namespace StarZarei\UniversalPOS\Users;

defined( 'ABSPATH' ) || exit;

final class Capabilities {
	public const ALL = array(
		'upos_access_pos', 'upos_create_sales', 'upos_view_customers',
		'upos_view_orders', 'upos_view_inventory', 'upos_add_products',
		'upos_edit_products', 'upos_change_prices', 'upos_apply_discounts',
		'upos_refund_orders', 'upos_record_expenses', 'upos_view_transactions',
		'upos_view_reports', 'upos_manage_users', 'upos_manage_settings',
	);

	public static function install(): void {
		add_role( 'upos_cashier', __( 'POS Cashier', 'universal-woocommerce-pos' ), array(
			'read' => true,
			'upos_access_pos' => true,
			'upos_create_sales' => true,
			'upos_view_inventory' => true,
		) );
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::ALL as $capability ) {
				$admin->add_cap( $capability );
			}
		}
	}
}
