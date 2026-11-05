<?php
namespace StarZarei\UniversalPOS\Payments;

defined( 'ABSPATH' ) || exit;

/** Delay core WooCommerce transactional email callbacks until a local POS commit. */
final class DeferredEmails {
	private array $callbacks = array();
	private array $events = array();

	public function capture(): void {
		global $wp_filter;
		WC()->mailer();
		foreach ( $wp_filter as $hook => $filters ) {
			foreach ( $filters->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( ! is_array( $function ) || 'WC_Emails' !== $function[0] || ! in_array( $function[1], array( 'send_transactional_email', 'queue_transactional_email' ), true ) ) { continue; }
					$listener = function ( ...$args ) use ( $hook ) { $this->events[] = array( $hook, $args ); };
					remove_action( $hook, $function, $priority );
					add_action( $hook, $listener, $priority, $callback['accepted_args'] );
					$this->callbacks[] = array( $hook, $priority, $callback, $listener );
				}
			}
		}
	}

	public function restore(): void {
		foreach ( $this->callbacks as [ $hook, $priority, $callback, $listener ] ) {
			remove_action( $hook, $listener, $priority );
			add_action( $hook, $callback['function'], $priority, $callback['accepted_args'] );
		}
	}

	public function send(): void {
		foreach ( $this->events as [ $hook, $args ] ) {
			try {
				\WC_Emails::send_queued_transactional_email( $hook, $args );
			} catch ( \Throwable $error ) {
				// Payment has committed: an email failure must not invite a second payment.
				wc_get_logger()->error( 'POS committed successfully but an email notification failed.', array( 'source' => 'universal-woocommerce-pos' ) );
			}
		}
	}
}
