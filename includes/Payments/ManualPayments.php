<?php
namespace StarZarei\UniversalPOS\Payments;

use StarZarei\UniversalPOS\Database\Schema;
use StarZarei\UniversalPOS\POS\SaleException;

defined( 'ABSPATH' ) || exit;

final class ManualPayments {
	public static function methods(): array {
		$labels = array(
			'cash' => __( 'Cash', 'universal-woocommerce-pos' ),
			'card_terminal' => __( 'Card terminal (manually confirmed)', 'universal-woocommerce-pos' ),
			'bank_transfer' => __( 'Bank transfer (manually confirmed)', 'universal-woocommerce-pos' ),
		);
		$enabled = get_option( 'upos_manual_payment_methods', array_keys( $labels ) );
		return array_intersect_key( $labels, array_flip( is_array( $enabled ) ? $enabled : array() ) );
	}

	public static function validate( array $payments, string $total ): void {
		$sum = 0;
		foreach ( $payments as $line ) {
			if ( ! isset( self::methods()[ $line['method'] ] ) ) {
				throw new SaleException( 'upos_method_disabled', __( 'This manual payment method is not enabled.', 'universal-woocommerce-pos' ) );
			}
			if ( 'cash' !== $line['method'] && '' === $line['reference'] ) {
				throw new SaleException( 'upos_reference_required', __( 'A tracking reference is required for non-cash payments.', 'universal-woocommerce-pos' ) );
			}
			$minor = Money::minor( $line['amount'] );
			if ( $minor <= 0 ) {
				throw new SaleException( 'upos_invalid_amount', __( 'Every payment amount must be greater than zero.', 'universal-woocommerce-pos' ) );
			}
			$sum += $minor;
		}
		if ( $sum !== Money::minor( $total ) ) {
			throw new SaleException( 'upos_payment_mismatch', __( 'The sum of payments must exactly match the server-calculated order total.', 'universal-woocommerce-pos' ), 409 );
		}
	}

	/** Caller holds the order lock and an InnoDB transaction. No payment gateway is called. */
	public static function record( \WC_Order $order, array $payments, int $actor ): void {
		global $wpdb;
		foreach ( $payments as $index => $line ) {
			$key = hash( 'sha256', 'upos:payment:' . $order->get_id() . ':' . $index );
			$common = array(
				'idempotency_key' => $key,
				'order_id' => $order->get_id(),
				'amount' => $line['amount'],
				'currency' => $order->get_currency(),
				'method' => $line['method'],
				'reference' => $line['reference'],
				'notes' => $line['notes'],
				'created_by' => $actor,
				'created_at' => current_time( 'mysql', true ),
			);
			if ( false === $wpdb->insert( Schema::table( 'payment_lines' ), array_merge( $common, array( 'status' => 'received' ) ) ) ) {
				throw new \RuntimeException( 'Cannot persist payment line.' );
			}
			$payment_id = $wpdb->insert_id;
			if ( false === $wpdb->insert( Schema::table( 'financial_transactions' ), array_merge( $common, array( 'type' => 'sale_receipt', 'payment_line_id' => $payment_id ) ) ) ) {
				throw new \RuntimeException( 'Cannot persist financial transaction.' );
			}
		}
		if ( ! $payments ) {
			$order->set_payment_method( 'upos_free' );
			$order->set_payment_method_title( __( 'No payment required', 'universal-woocommerce-pos' ) );
		} else {
			$order->set_payment_method( count( $payments ) === 1 ? 'upos_' . $payments[0]['method'] : 'upos_split' );
			$order->set_payment_method_title( count( $payments ) === 1 ? self::methods()[ $payments[0]['method'] ] : __( 'Manual split payment', 'universal-woocommerce-pos' ) );
		}
	}
}
