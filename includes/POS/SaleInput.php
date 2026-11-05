<?php
namespace StarZarei\UniversalPOS\POS;

defined( 'ABSPATH' ) || exit;

final class SaleInput {
	public static function key( string $key ): string {
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{8,128}$/D', $key ) ) {
			throw new SaleException( 'upos_key_required', __( 'Provide an Idempotency-Key header of 8 to 128 letters, digits or . _ : - characters.', 'universal-woocommerce-pos' ) );
		}
		return $key;
	}

	public static function prepare( array $input, bool $require_customer = true ): array {
		self::fields( $input, array( 'items', 'billing', 'customer_id', 'note', 'coupon', 'manual_discount', 'parked_cart_key' ) );
		$lines = $input['items'] ?? null;
		if ( ! is_array( $lines ) || ! array_is_list( $lines ) || count( $lines ) < 1 || count( $lines ) > 100 ) {
			throw new SaleException( 'upos_items_required', __( 'Provide between 1 and 100 product lines.', 'universal-woocommerce-pos' ) );
		}
		$items = array();
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) { self::invalid(); }
			self::fields( $line, array( 'product_id', 'quantity' ) );
			$id = $line['product_id'] ?? null;
			$qty = $line['quantity'] ?? null;
			if ( ! is_int( $id ) || $id < 1 || ! is_int( $qty ) || $qty < 1 || $qty > 1000 || isset( $items[ $id ] ) ) {
				throw new SaleException( 'upos_invalid_items', __( 'Use unique product IDs and integer quantities from 1 to 1000.', 'universal-woocommerce-pos' ) );
			}
			$items[ $id ] = array( 'product_id' => $id, 'quantity' => $qty );
		}
		ksort( $items );
		$customer_id = $input['customer_id'] ?? 0;
		if ( ! is_int( $customer_id ) || $customer_id < 0 ) { self::invalid(); }
		$billing = $input['billing'] ?? array();
		if ( ! is_array( $billing ) ) { self::invalid(); }
		$fields = array( 'first_name', 'last_name', 'phone', 'email', 'country', 'state', 'city', 'postcode', 'address_1', 'address_2' );
		self::fields( $billing, $fields );
		$defaults = array_fill_keys( $fields, '' );
		if ( $customer_id ) {
			if ( ! current_user_can( 'upos_view_customers' ) || ! get_user_by( 'id', $customer_id ) ) {
				throw new SaleException( 'upos_customer_forbidden', __( 'You cannot assign this customer to a sale.', 'universal-woocommerce-pos' ), 403 );
			}
			$customer = new \WC_Customer( $customer_id );
			$defaults = array_merge( $defaults, array_intersect_key( $customer->get_billing(), $defaults ) );
		}
		foreach ( array_merge( $defaults, $billing ) as $field => $value ) {
			$defaults[ $field ] = self::text( $value, 200 );
		}
		if ( $require_customer || '' !== $defaults['phone'] ) { $defaults['phone'] = self::phone( $defaults['phone'] ); }
		if ( $require_customer && ( '' === $defaults['first_name'] || '' === $defaults['last_name'] ) ) {
			throw new SaleException( 'upos_customer_required', __( 'A first name, last name and valid phone number are required.', 'universal-woocommerce-pos' ) );
		}
		if ( '' !== $defaults['email'] && ! is_email( $defaults['email'] ) ) {
			throw new SaleException( 'upos_invalid_email', __( 'Enter a valid email address or leave it empty.', 'universal-woocommerce-pos' ) );
		}
		if ( '' === $defaults['country'] ) { $defaults['country'] = WC()->countries->get_base_country(); }
		if ( ! isset( WC()->countries->get_countries()[ $defaults['country'] ] ) ) { self::invalid(); }
		$result = array( 'items' => array_values( $items ), 'billing' => $defaults, 'customer_id' => $customer_id, 'note' => self::text( $input['note'] ?? '', 1000 ) );
		$coupon = self::text( $input['coupon'] ?? '', 100 );
		if ( '' !== $coupon ) { $result['coupon'] = wc_format_coupon_code( $coupon ); }
		$manual = $input['manual_discount'] ?? null;
		if ( null !== $manual ) {
			if ( ! is_array( $manual ) ) { self::invalid(); }
			self::fields( $manual, array( 'type', 'value', 'reason' ) );
			if ( ! current_user_can( 'upos_apply_discounts' ) ) {
				throw new SaleException( 'upos_discount_forbidden', __( 'You are not allowed to apply a manual discount.', 'universal-woocommerce-pos' ), 403 );
			}
			if ( ! in_array( $manual['type'] ?? '', array( 'amount', 'percent' ), true ) || ! is_string( $manual['value'] ?? null ) ) { self::invalid(); }
			$value = $manual['value'];
			if ( 'percent' === $manual['type'] ) {
				if ( ! preg_match( '/^(?:[0-9]|[1-9][0-9])(?:\.[0-9]{1,2})?$|^100(?:\.0{1,2})?$/D', $value ) || (float) $value <= 0 ) { self::invalid(); }
			} elseif ( \StarZarei\UniversalPOS\Payments\Money::minor( $value ) <= 0 ) { self::invalid(); }
			$reason = self::text( $manual['reason'] ?? '', 200 );
			if ( '' === $reason || '' !== $coupon ) {
				throw new SaleException( 'upos_discount_invalid', __( 'Provide a reason and use either a coupon or a manual discount, not both.', 'universal-woocommerce-pos' ) );
			}
			$result['manual_discount'] = array( 'type' => $manual['type'], 'value' => $value, 'reason' => $reason );
		}
		if ( isset( $input['parked_cart_key'] ) ) {
			if ( ! is_string( $input['parked_cart_key'] ) ) { self::invalid(); }
			$result['parked_cart_key'] = self::key( $input['parked_cart_key'] );
		}
		return $result;
	}

	public static function payment( array $input ): array {
		self::fields( $input, array( 'received', 'expected_total', 'payments' ) );
		if ( true !== ( $input['received'] ?? false ) ) {
			throw new SaleException( 'upos_receipt_confirmation_required', __( 'Explicitly confirm that the listed funds have actually been received.', 'universal-woocommerce-pos' ) );
		}
		if ( ! isset( $input['expected_total'] ) || ! is_string( $input['expected_total'] ) ) { self::invalid(); }
		$payments = $input['payments'] ?? null;
		if ( ! is_array( $payments ) || ! array_is_list( $payments ) || count( $payments ) > 20 ) { self::invalid(); }
		$result = array();
		foreach ( $payments as $line ) {
			if ( ! is_array( $line ) ) { self::invalid(); }
			self::fields( $line, array( 'method', 'amount', 'reference', 'notes' ) );
			if ( ! isset( $line['method'], $line['amount'] ) || ! is_string( $line['method'] ) || ! is_string( $line['amount'] ) ) { self::invalid(); }
			$result[] = array( 'method' => $line['method'], 'amount' => $line['amount'], 'reference' => self::text( $line['reference'] ?? '', 191 ), 'notes' => self::text( $line['notes'] ?? '', 1000 ) );
		}
		return array( 'received' => true, 'expected_total' => $input['expected_total'], 'payments' => $result );
	}

	public static function hash( array $input ): string { return hash( 'sha256', wp_json_encode( $input ) ); }

	private static function fields( array $input, array $allowed ): void {
		if ( array_diff( array_keys( $input ), $allowed ) ) {
			throw new SaleException( 'upos_unknown_field', __( 'The request contains unsupported fields. Prices and totals are calculated by the server.', 'universal-woocommerce-pos' ) );
		}
	}

	private static function text( $value, int $max ): string {
		if ( ! is_string( $value ) || mb_strlen( $value ) > $max ) { self::invalid(); }
		return sanitize_text_field( $value );
	}

	private static function phone( string $value ): string {
		$value = strtr( $value, array_combine( preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY ), str_split( '01234567890123456789' ) ) );
		$value = preg_replace( '/[\s()\-]/u', '', $value );
		if ( ! preg_match( '/^\+?[0-9]{7,15}$/D', $value ) ) {
			throw new SaleException( 'upos_invalid_phone', __( 'Enter a valid phone number containing 7 to 15 digits.', 'universal-woocommerce-pos' ) );
		}
		return $value;
	}

	private static function invalid(): void {
		throw new SaleException( 'upos_invalid_request', __( 'The sale request has invalid fields or values.', 'universal-woocommerce-pos' ) );
	}
}
