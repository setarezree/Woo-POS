<?php
namespace StarZarei\UniversalPOS\POS;

defined( 'ABSPATH' ) || exit;

final class SaleException extends \RuntimeException {
	public function __construct( public string $error_code, string $message, public int $status = 400 ) {
		parent::__construct( $message );
	}
}
