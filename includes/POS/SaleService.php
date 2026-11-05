<?php
namespace StarZarei\UniversalPOS\POS;

use Automattic\WooCommerce\Checkout\Helpers\ReserveStock;
use StarZarei\UniversalPOS\Database\AtomicOperation;
use StarZarei\UniversalPOS\Database\Schema;
use StarZarei\UniversalPOS\Orders\SalesChannel;
use StarZarei\UniversalPOS\Payments\ManualPayments;
use StarZarei\UniversalPOS\Payments\Money;

defined( 'ABSPATH' ) || exit;

final class SaleService {
	public const HOLD_SECONDS = 300;

	public function prepare( array $input, string $key ): array {
		$this->authorize();
		$key = hash( 'sha256', get_current_user_id() . ':' . SaleInput::key( $key ) );
		$input = SaleInput::prepare( $input );
		$hash = SaleInput::hash( $input );
		return AtomicOperation::run( 'prepare:' . $key, function () use ( $input, $key, $hash ) {
			global $wpdb;
			$previous = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE request_key = %s', Schema::table( 'sale_requests' ), $key ) );
			if ( $previous ) {
				if ( ! hash_equals( $previous->payload_hash, $hash ) ) { $this->conflict(); }
				return $this->receipt( $this->owned_order( (int) $previous->order_id ) );
			}
			$products = $this->products( $input['items'] );
			$order = new \WC_Order();
			$order->set_status( 'pending' );
			$order->set_currency( get_woocommerce_currency() );
			$order->set_prices_include_tax( wc_prices_include_tax() );
			$order->set_customer_id( $input['customer_id'] );
			$order->set_address( $input['billing'], 'billing' );
			$order->set_address( array_diff_key( $input['billing'], array_flip( array( 'email', 'phone' ) ) ), 'shipping' );
			$order->set_customer_note( $input['note'] );
			SalesChannel::mark_pos( $order, get_current_user_id() );
			$order->update_meta_data( '_upos_expires_at', time() + self::HOLD_SECONDS );
			$order->update_meta_data( '_upos_price_decimals', wc_get_price_decimals() );
			if ( $input['customer_id'] ) {
				$customer = new \WC_Customer( $input['customer_id'] );
				$order->update_meta_data( 'is_vat_exempt', $customer->get_is_vat_exempt() ? 'yes' : 'no' );
			}
			$order->save();
			if ( isset( $input['parked_cart_key'] ) ) {
				ParkedCarts::consume( $input['parked_cart_key'], $order->get_id() );
				$order->update_meta_data( '_upos_parked_cart_key', $input['parked_cart_key'] );
			}
			foreach ( $input['items'] as $line ) {
				$order->add_product( $products[ $line['product_id'] ], $line['quantity'], array( 'order' => $order ) );
			}
			$order->calculate_totals();
			Discounts::apply( $order, $input );
			Money::minor( $this->total( $order ) );
			$this->reserve( $order );
			Expiry::schedule( $order );
			if ( false === $wpdb->insert( Schema::table( 'sale_requests' ), array( 'request_key' => $key, 'payload_hash' => $hash, 'order_id' => $order->get_id(), 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) ) ) {
				throw new \RuntimeException( 'Cannot persist sale request.' );
			}
			$this->audit( $order, 'sale_prepared' );
			if ( isset( $input['manual_discount'] ) ) { $this->audit( $order, 'manual_discount_applied' ); }
			return $this->receipt( $order );
		} );
	}

	public function pay( int $id, array $input, string $key ): array {
		$this->authorize();
		$key = hash( 'sha256', get_current_user_id() . ':' . SaleInput::key( $key ) );
		$input = SaleInput::payment( $input );
		$hash = SaleInput::hash( $input );
		return AtomicOperation::run( 'order:' . $id, function () use ( $id, $input, $key, $hash ) {
			$order = $this->owned_order( $id );
			$old_key = $order->get_meta( '_upos_payment_key', true );
			if ( $old_key ) {
				if ( ! hash_equals( $old_key, $key ) || ! hash_equals( (string) $order->get_meta( '_upos_payment_hash', true ), $hash ) ) { $this->conflict(); }
				// A later refund/cancellation must never be turned into a second payment.
				return $this->receipt( $order );
			}
			$this->pending( $order );
			Discounts::verify( $order );
			if ( get_woocommerce_currency() !== $order->get_currency() || wc_get_price_decimals() !== (int) $order->get_meta( '_upos_price_decimals', true ) || Money::minor( $input['expected_total'] ) !== Money::minor( $this->total( $order ) ) ) {
				throw new SaleException( 'upos_quote_changed', __( 'The order total or currency has changed. Review the sale before confirming payment.', 'universal-woocommerce-pos' ), 409 );
			}
			ManualPayments::validate( $input['payments'], $this->total( $order ) );
			$this->reserve( $order, 6 ); // Different expiry also ensures MySQL reports an updated reservation row.
			ManualPayments::record( $order, $input['payments'], get_current_user_id() );
			$order->update_meta_data( '_upos_payment_key', $key );
			$order->update_meta_data( '_upos_payment_hash', $hash );
			$order->save();
			$changes = array();
			$listener = static function ( $item, $change, $changed_order ) use ( $order, &$changes ) {
				if ( $changed_order->get_id() === $order->get_id() ) { $changes[ $item->get_id() ] = array( $item, $change ); }
			};
			add_action( 'woocommerce_reduce_order_item_stock', $listener, 10, 3 );
			try {
				if ( ! $order->payment_complete() || ! $order->is_paid() ) {
					throw new \RuntimeException( 'WooCommerce did not complete the payment.' );
				}
			} finally {
				remove_action( 'woocommerce_reduce_order_item_stock', $listener, 10 );
			}
			$order = wc_get_order( $order->get_id() );
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				if ( $product && $product->managing_stock() && (float) $item->get_meta( '_reduced_stock', true ) !== (float) $item->get_quantity() ) {
					throw new \RuntimeException( 'WooCommerce did not reduce the expected stock.' );
				}
			}
			foreach ( $changes as [ $item, $change ] ) { $this->stock_movement( $order, $item, $change ); }
			( new ReserveStock() )->release_stock_for_order( $order );
			$this->audit( $order, 'sale_paid' );
			return $this->receipt( $order );
		} );
	}

	public function cancel( int $id ): array {
		$this->authorize();
		return AtomicOperation::run( 'order:' . $id, function () use ( $id ) {
			$order = $this->owned_order( $id );
			if ( $order->get_meta( '_upos_payment_key', true ) ) {
				throw new SaleException( 'upos_refund_required', __( 'A received payment requires a refund; it cannot be cancelled here.', 'universal-woocommerce-pos' ), 409 );
			}
			if ( $order->has_status( 'cancelled' ) ) { return $this->receipt( $order ); }
			if ( ! $order->has_status( 'pending' ) ) { $this->conflict(); }
			$order->update_status( 'cancelled' );
			( new ReserveStock() )->release_stock_for_order( $order );
			$this->audit( $order, 'sale_cancelled' );
			return $this->receipt( $order );
		} );
	}

	public function get( int $id ): array {
		$this->authorize();
		return $this->receipt( $this->owned_order( $id ) );
	}

	/** Only this cashier's POS records, independent of WooCommerce's order storage mode. */
	public function recent(): array {
		$this->authorize();
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM %i WHERE created_by = %d AND order_id > 0 ORDER BY id DESC LIMIT 15', Schema::table( 'sale_requests' ), get_current_user_id() ) );
		$receipts = array();
		foreach ( $ids as $id ) {
			try { $receipts[] = $this->get( (int) $id ); }
			catch ( SaleException $error ) { if ( 404 !== $error->status ) { throw $error; } }
		}
		return array( 'sales' => $receipts );
	}

	public function lookup( string $key, bool $abandon = false ): array {
		$this->authorize();
		$key = hash( 'sha256', get_current_user_id() . ':' . SaleInput::key( $key ) );
		return AtomicOperation::run( 'prepare:' . $key, function () use ( $key, $abandon ) {
			global $wpdb;
			$previous = $wpdb->get_row( $wpdb->prepare( 'SELECT order_id FROM %i WHERE request_key = %s', Schema::table( 'sale_requests' ), $key ) );
			if ( $previous ) {
				return (int) $previous->order_id ? $this->get( (int) $previous->order_id ) : array( 'abandoned' => true );
			}
			if ( ! $abandon ) {
				throw new SaleException( 'upos_attempt_not_found', __( 'No sale has been recorded for this attempt. Check again or abandon the attempt before starting another.', 'universal-woocommerce-pos' ), 404 );
			}
			// Tombstone prevents a delayed POST from creating a sale after the cashier has abandoned it.
			if ( false === $wpdb->insert( Schema::table( 'sale_requests' ), array( 'request_key' => $key, 'payload_hash' => hash( 'sha256', 'abandoned' ), 'order_id' => 0, 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) ) ) {
				throw new \RuntimeException( 'Cannot abandon sale attempt.' );
			}
			return array( 'abandoned' => true );
		} );
	}

	/** Action Scheduler callback: no browser session; refuses paid/non-POS orders. */
	public function expire( int $id ): void {
		AtomicOperation::run( 'order:' . $id, function () use ( $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof \WC_Order || 'pos' !== SalesChannel::get( $order ) || ! $order->has_status( 'pending' ) || $order->get_meta( '_upos_payment_key', true ) ) { return; }
			$expires = (int) $order->get_meta( '_upos_expires_at', true );
			if ( ! $expires || $expires > time() ) { return; }
			$order->update_status( 'cancelled' );
			( new ReserveStock() )->release_stock_for_order( $order );
			$this->audit( $order, 'sale_expired' );
		} );
	}

	private function authorize(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'upos_access_pos' ) || ! current_user_can( 'upos_create_sales' ) ) {
			throw new SaleException( 'upos_forbidden', __( 'You do not have access to this operation.', 'universal-woocommerce-pos' ), rest_authorization_required_code() );
		}
	}

	private function owned_order( int $id ): \WC_Order {
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order || 'pos' !== SalesChannel::get( $order ) || ! $order->get_meta( '_upos_expires_at', true ) ||
			( get_current_user_id() !== (int) $order->get_meta( SalesChannel::CASHIER_KEY, true ) && ! ( current_user_can( 'upos_view_orders' ) && current_user_can( 'edit_shop_order', $id ) ) ) ) {
			throw new SaleException( 'upos_not_found', __( 'The requested item was not found.', 'universal-woocommerce-pos' ), 404 );
		}
		return $order;
	}

	private function pending( \WC_Order $order ): void {
		if ( ! $order->has_status( 'pending' ) || $order->get_date_paid() ) { $this->conflict(); }
		if ( (int) $order->get_meta( '_upos_expires_at', true ) <= time() ) {
			throw new SaleException( 'upos_quote_expired', __( 'The stock reservation has expired. Cancel this sale and prepare a new one.', 'universal-woocommerce-pos' ), 409 );
		}
	}

	private function products( array $lines ): array {
		$products = array();
		$quantities = array();
		foreach ( $lines as $line ) {
			$product = wc_get_product( $line['product_id'] );
			if ( ! $product || ! $product->is_type( array( 'simple', 'variation' ) ) || 'publish' !== $product->get_status() || ! $product->is_purchasable() || ! $product->is_in_stock() ||
				( $product->is_sold_individually() && $line['quantity'] > 1 ) ) {
				throw new SaleException( 'upos_product_unavailable', __( 'A selected product is unavailable or requires a specific purchasable variation.', 'universal-woocommerce-pos' ), 409 );
			}
			if ( $product->is_type( 'variation' ) ) {
				$parent = wc_get_product( $product->get_parent_id() );
				if ( ! $parent || 'publish' !== $parent->get_status() ) { $this->conflict(); }
				foreach ( $parent->get_attributes() as $attribute ) {
					if ( $attribute->get_variation() && '' === ( $product->get_attributes()[ $attribute->get_name() ] ?? '' ) ) {
						throw new SaleException( 'upos_unspecified_variation', __( 'Choose a variation with all options explicitly defined. Wildcard variations are not supported yet.', 'universal-woocommerce-pos' ) );
					}
				}
			}
			$products[ $line['product_id'] ] = $product;
			if ( $product->managing_stock() ) {
				$stock_id = $product->get_stock_managed_by_id();
				$quantities[ $stock_id ] = ( $quantities[ $stock_id ] ?? 0 ) + $line['quantity'];
				$stock = wc_get_product( $stock_id );
				if ( ! $stock || $stock->get_stock_quantity() < $quantities[ $stock_id ] ) { $this->stock_error(); }
			}
		}
		return $products;
	}

	private function reserve( \WC_Order $order, int $minutes = 5 ): void {
		$lines = array();
		foreach ( $order->get_items() as $item ) { $lines[] = array( 'product_id' => $item->get_variation_id() ?: $item->get_product_id(), 'quantity' => $item->get_quantity() ); }
		$products = $this->products( $lines );
		if ( (int) get_option( 'woocommerce_schema_version', 0 ) < 430 ) { throw new \RuntimeException( 'WooCommerce stock reservations are unavailable.' ); }
		// POS requires physical stock even when the online store allows backorders.
		$no_backorders = static fn( $allowed, $id ) => isset( $products[ $id ] ) ? false : $allowed;
		$duration = static fn( $original, $held_order ) => $held_order->get_id() === $order->get_id() ? $minutes : $original;
		add_filter( 'woocommerce_product_backorders_allowed', $no_backorders, PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_order_hold_stock_minutes', $duration, PHP_INT_MAX, 2 );
		try {
			( new ReserveStock() )->reserve_stock_for_order( $order, $minutes );
		} catch ( \Automattic\WooCommerce\Checkout\Helpers\ReserveStockException $error ) {
			$this->stock_error();
		} finally {
			remove_filter( 'woocommerce_product_backorders_allowed', $no_backorders, PHP_INT_MAX );
			remove_filter( 'woocommerce_order_hold_stock_minutes', $duration, PHP_INT_MAX );
		}
	}

	private function total( \WC_Order $order ): string {
		$precision = $order->get_meta( '_upos_price_decimals', true );
		return wc_format_decimal( $order->get_total(), '' === $precision ? wc_get_price_decimals() : (int) $precision );
	}

	private function receipt( \WC_Order $order ): array {
		global $wpdb;
		$lines = $wpdb->get_results( $wpdb->prepare( 'SELECT id, amount, currency, method, reference, created_at FROM %i WHERE order_id = %d ORDER BY id', Schema::table( 'payment_lines' ), $order->get_id() ), ARRAY_A );
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array( 'product_id' => $item->get_product_id(), 'variation_id' => $item->get_variation_id(), 'name' => $item->get_name(), 'quantity' => $item->get_quantity(), 'subtotal' => $item->get_subtotal(), 'total' => $item->get_total(), 'tax' => $item->get_total_tax() );
		}
		return array( 'id' => $order->get_id(), 'number' => $order->get_order_number(), 'status' => $order->get_status(), 'paid' => $order->is_paid(), 'currency' => $order->get_currency(), 'total' => $this->total( $order ),
			'coupon' => $order->get_meta( '_upos_coupon_policy', true ) ? ( $order->get_coupon_codes()[0] ?? '' ) : '', 'manual_discount' => $order->get_meta( '_upos_manual_discount', true ) ?: null,
			'price_decimals' => (int) $order->get_meta( '_upos_price_decimals', true ), 'currency_symbol_text' => html_entity_decode( wp_strip_all_tags( get_woocommerce_currency_symbol( $order->get_currency() ) ), ENT_QUOTES, 'UTF-8' ),
			'tax' => $order->get_total_tax(), 'discount' => $order->get_discount_total(), 'channel' => SalesChannel::get( $order ), 'cashier_id' => (int) $order->get_meta( SalesChannel::CASHIER_KEY, true ),
			'expires_at' => gmdate( 'c', (int) $order->get_meta( '_upos_expires_at', true ) ), 'expired' => (int) $order->get_meta( '_upos_expires_at', true ) <= time(),
			'items' => $items, 'payments' => $lines );
	}

	private function audit( \WC_Order $order, string $action ): void {
		global $wpdb;
		if ( false === $wpdb->insert( Schema::table( 'audit_logs' ), array( 'action' => $action, 'object_type' => 'order', 'object_id' => $order->get_id(), 'order_id' => $order->get_id(), 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ), 'context' => wp_json_encode( array( 'currency' => $order->get_currency(), 'total' => $this->total( $order ) ) ) ) ) ) {
			throw new \RuntimeException( 'Cannot persist audit event.' );
		}
	}

	private function stock_movement( \WC_Order $order, \WC_Order_Item_Product $item, array $change ): void {
		global $wpdb;
		if ( false === $wpdb->insert( Schema::table( 'stock_movements' ), array( 'event_key' => hash( 'sha256', 'sale:' . $order->get_id() . ':' . $item->get_id() ), 'product_id' => $item->get_product_id(), 'variation_id' => $item->get_variation_id(),
			'quantity_before' => $change['from'], 'quantity_after' => $change['to'], 'quantity_change' => $change['to'] - $change['from'], 'type' => 'pos_sale', 'order_id' => $order->get_id(), 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) ) ) {
			throw new \RuntimeException( 'Cannot persist stock movement.' );
		}
	}

	private function conflict(): void { throw new SaleException( 'upos_sale_conflict', __( 'This idempotency key or sale has already been used for a different operation.', 'universal-woocommerce-pos' ), 409 ); }
	private function stock_error(): void { throw new SaleException( 'upos_insufficient_stock', __( 'There is not enough unreserved stock to complete this sale.', 'universal-woocommerce-pos' ), 409 ); }
}
