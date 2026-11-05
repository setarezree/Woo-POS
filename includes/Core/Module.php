<?php
namespace StarZarei\UniversalPOS\Core;

defined( 'ABSPATH' ) || exit;

interface Module {
	public function register(): void;
}
