<?php
namespace StarZarei\UniversalPOS\POS;

use StarZarei\UniversalPOS\Payments\Money;

defined( 'ABSPATH' ) || exit;

final class Discounts {
	public static function apply( \WC_Order $order, array $input ): void {
		if ( ! isset( $input['coupon'] ) && ! isset( $input['manual_discount'] ) ) { return; }
		$manual = $input['manual_discount'] ?? null;
		if ( $manual ) {
			$coupon = new \WC_Coupon();
			$coupon->set_code( 'upos-manual-' . wp_generate_uuid4() );
			$coupon->set_virtual( true );
			$coupon->set_discount_type( 'percent' === $manual['type'] ? 'percent' : 'fixed_cart' );
			$coupon->set_amount( $manual['value'] );
			$basis = (float) $order->get_subtotal() + ( $order->get_prices_include_tax() ? (float) $order->get_total_tax() : 0 );
			if ( 'amount' === $manual['type'] && Money::minor( $manual['value'] ) > Money::minor( wc_format_decimal( $basis, wc_get_price_decimals() ) ) ) {
				throw new SaleException( 'upos_discount_too_large', __( 'The manual discount cannot exceed the product subtotal.', 'universal-woocommerce-pos' ) );
			}
			$order->update_meta_data( '_upos_manual_discount', $manual );
			$order->update_meta_data( '_upos_discount_actor', get_current_user_id() );
		} else {
			if ( ! wc_coupons_enabled() ) { self::invalid(); }
			$coupon = new \WC_Coupon( $input['coupon'] );
			if ( ! $coupon->get_id() || 'publish' !== $coupon->get_status() ) { self::invalid(); }
			if ( $coupon->get_usage_limit_per_user() && ! $order->get_customer_id() && ! $order->get_billing_email() ) {
				throw new SaleException( 'upos_coupon_email_required', __( 'This coupon requires a customer account or billing email for its per-customer limit.', 'universal-woocommerce-pos' ) );
			}
			$order->update_meta_data( '_upos_coupon_policy', self::policy( $coupon ) );
		}
		$actor = get_current_user_id();
		try {
			// WooCommerce must validate against the purchaser, never the cashier's email/account.
			wp_set_current_user( $order->get_customer_id() );
			$result = $order->apply_coupon( $coupon );
			if ( is_wp_error( $result ) ) { self::invalid(); }
		} finally { wp_set_current_user( $actor ); }
		if ( ! $manual ) { self::limits( new \WC_Coupon( $coupon->get_id() ), $order ); }
		$order->save();
	}

	public static function verify( \WC_Order $order ): void {
		if ( $order->get_meta( '_upos_manual_discount', true ) ) {
			if ( ! current_user_can( 'upos_apply_discounts' ) ) {
				throw new SaleException( 'upos_discount_forbidden', __( 'You are not allowed to apply a manual discount.', 'universal-woocommerce-pos' ), 403 );
			}
			return;
		}
		$policy = $order->get_meta( '_upos_coupon_policy', true );
		if ( ! $policy ) { return; }
		$codes = $order->get_coupon_codes();
		$coupon = new \WC_Coupon( $codes[0] ?? '' );
		if ( ! wc_coupons_enabled() || ! $coupon->get_id() || 'publish' !== $coupon->get_status() || ! hash_equals( (string) $policy, self::policy( $coupon ) ) || ( $coupon->get_date_expires() && $coupon->get_date_expires()->getTimestamp() < time() ) ) {
			throw new SaleException( 'upos_coupon_changed', __( 'The coupon has changed or expired. Cancel this unpaid sale and review a new quote.', 'universal-woocommerce-pos' ), 409 );
		}
		self::limits( $coupon, $order );
	}

	private static function policy( \WC_Coupon $coupon ): string {
		$data = $coupon->get_data();
		unset( $data['usage_count'], $data['used_by'], $data['date_modified'], $data['date_created'], $data['meta_data'] );
		return hash( 'sha256', wp_json_encode( $data ) );
	}

	private static function limits( \WC_Coupon $coupon, \WC_Order $order ): void {
		$store = $coupon->get_data_store();
		// apply_coupon records pending usage via native atomic coupon CRUD. Check the postcondition
		// while that write lock is still held, including native online-checkout tentative holds.
		if ( $coupon->get_usage_limit() && $coupon->get_usage_count() + (int) $store->get_tentative_usage_count( $coupon->get_id() ) > $coupon->get_usage_limit() ) { self::invalid(); }
		if ( $coupon->get_usage_limit_per_user() ) {
			$count = $order->get_customer_id() ? $store->get_usage_by_user_id( $coupon, $order->get_customer_id() ) : $store->get_usage_by_email( $coupon, $order->get_billing_email() );
			if ( $count > $coupon->get_usage_limit_per_user() ) { self::invalid(); }
		}
	}

	private static function invalid(): void {
		throw new SaleException( 'upos_coupon_invalid', __( 'The coupon cannot be applied. Review its expiry, usage limits and customer or product restrictions.', 'universal-woocommerce-pos' ), 409 );
	}
}
