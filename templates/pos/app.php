<?php
use StarZarei\UniversalPOS\POS\App;
use StarZarei\UniversalPOS\POS\Strings;

defined( 'ABSPATH' ) || exit;
$rtl = 'fa_IR' === Strings::locale();
$t = static fn( $text ) => esc_html( Strings::t( $text ) );
?>
<!doctype html>
<html lang="<?php echo $rtl ? 'fa' : 'en'; ?>" dir="<?php echo $rtl ? 'rtl' : 'ltr'; ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title><?php echo $t( 'Point of sale' ) . ' — ' . esc_html( get_bloginfo( 'name' ) ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( App::asset( 'css/pos.css' ) ); ?>">
	<script id="upos-config" type="application/json"><?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
	<script defer src="<?php echo esc_url( App::asset( 'js/pos.js' ) ); ?>"></script>
</head>
<body>
<a class="skip-link" href="#catalog"><?php echo $t( 'Skip to products' ); ?></a>
<header class="app-header">
	<div class="store-identity"><span class="store-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 10v10h16V10M3 10l2-6h14l2 6M3 10c0 3 4 3 4 0 0 3 5 3 5 0 0 3 5 3 5 0 0 3 4 3 4 0M9 20v-6h6v6"/></svg></span><div><h1><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1><span><?php echo $t( 'Point of sale' ); ?></span></div></div>
	<div class="header-actions"><span id="connection" class="connection"><?php echo $t( 'Connecting to store…' ); ?></span><span class="operator"><?php echo esc_html( wp_get_current_user()->display_name ); ?></span><a href="<?php echo esc_url( wp_logout_url( App::url() ) ); ?>"><?php echo $t( 'Sign out' ); ?></a></div>
</header>
<div class="app-shell">
	<nav class="mobile-tabs" aria-label="<?php echo esc_attr( Strings::t( 'Workspace' ) ); ?>"><button type="button" class="selected" data-tab="catalog" aria-pressed="true"><?php echo $t( 'Products' ); ?></button><button type="button" data-tab="order" aria-pressed="false"><?php echo $t( 'Basket' ); ?> <span id="mobile-count">0</span></button></nav>
	<main id="catalog" class="catalog-pane" tabindex="-1">
		<div class="catalog-heading"><h2><?php echo $t( 'Products' ); ?></h2><button id="refresh-products" type="button" class="text-button"><?php echo $t( 'Refresh stock' ); ?></button></div>
		<form id="search-form" class="search-form" role="search">
			<label class="search-box"><span class="sr-only"><?php echo $t( 'Search name, SKU or barcode' ); ?></span><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><input id="search" type="search" autocomplete="off" placeholder="<?php echo esc_attr( Strings::t( 'Search name, SKU or barcode' ) ); ?>" maxlength="100" aria-controls="product-list"></label>
			<button type="submit" class="secondary"><?php echo $t( 'Search' ); ?></button>
		</form>
		<div class="catalog-meta"><p id="catalog-status" role="status" aria-live="polite"><?php echo $t( 'Loading live products…' ); ?></p><span class="keyboard-hint"><?php echo $t( '/ to search · Enter to scan a code' ); ?></span></div>
		<div id="variant-heading" class="variant-heading" hidden><button type="button" id="back-products" class="secondary"><?php echo $t( 'All products' ); ?></button><h3 id="variant-title"></h3></div>
		<div id="catalog-error" class="notice error" role="alert" hidden></div>
		<div id="product-list" class="product-grid" aria-busy="true"></div>
		<div class="pagination"><button type="button" id="previous-page" class="secondary" disabled><?php echo $t( 'Previous' ); ?></button><span id="page-label"></span><button type="button" id="next-page" class="secondary" disabled><?php echo $t( 'Next' ); ?></button></div>
	</main>
	<aside id="order" class="order-pane" aria-label="<?php echo esc_attr( Strings::t( 'Current sale' ) ); ?>">
		<div class="order-heading"><div><h2><?php echo $t( 'Basket' ); ?></h2><p id="order-meta"><?php echo $t( 'A new in-person sale' ); ?></p></div><button type="button" id="clear-cart" class="text-button" disabled><?php echo $t( 'Clear' ); ?></button></div>
		<div id="messages" class="notice" role="status" aria-live="polite" tabindex="-1" hidden></div>
		<div id="cart-items" class="cart-items"></div>
		<section class="totals" aria-label="<?php echo esc_attr( Strings::t( 'Sale totals' ) ); ?>"><dl id="totals-lines"></dl><p id="estimate-note" class="muted"><?php echo $t( 'This is an estimate. The server calculates the final price and tax when you prepare the sale.' ); ?></p></section>
		<form id="customer-form" class="customer-form">
			<h3><?php echo $t( 'Guest customer' ); ?></h3>
			<div class="field-row"><label><?php echo $t( 'First name' ); ?><input id="first-name" name="first_name" autocomplete="given-name" required maxlength="200"></label><label><?php echo $t( 'Last name' ); ?><input id="last-name" name="last_name" autocomplete="family-name" required maxlength="200"></label></div>
			<label><?php echo $t( 'Mobile number' ); ?><input id="phone" name="phone" type="tel" autocomplete="tel" required maxlength="30" dir="ltr"></label>
			<details class="more-details"><summary><?php echo $t( 'Email and address (optional)' ); ?></summary><div class="details-fields">
				<label><?php echo $t( 'Email' ); ?><input name="email" type="email" autocomplete="email" maxlength="200" dir="ltr"></label>
				<label><?php echo $t( 'Country' ); ?><select name="country" id="country" autocomplete="country"></select></label>
				<div class="field-row"><label><?php echo $t( 'State / province' ); ?><input name="state" id="customer-state" autocomplete="address-level1" maxlength="200"></label><label><?php echo $t( 'City' ); ?><input name="city" autocomplete="address-level2" maxlength="200"></label></div>
				<label><?php echo $t( 'Address' ); ?><input name="address_1" autocomplete="address-line1" maxlength="200"></label><label><?php echo $t( 'Postal code' ); ?><input name="postcode" autocomplete="postal-code" maxlength="200"></label>
			</div></details>
			<div class="discount-controls"><label><?php echo $t( 'Discount type' ); ?><select id="discount-kind"><option value="none"><?php echo $t( 'No discount' ); ?></option><option value="coupon"><?php echo $t( 'WooCommerce coupon' ); ?></option><?php if ( $config['canDiscount'] ) : ?><option value="amount"><?php echo $t( 'Manual amount' ); ?></option><option value="percent"><?php echo $t( 'Manual percentage' ); ?></option><?php endif; ?></select></label><label id="coupon-field" hidden><?php echo $t( 'Coupon code' ); ?><input id="coupon-code" maxlength="100" autocomplete="off" disabled></label><div id="manual-fields" hidden><label><?php echo $t( 'Discount value' ); ?><input id="manual-value" inputmode="decimal" dir="ltr" autocomplete="off" disabled></label><label><?php echo $t( 'Internal discount reason' ); ?><input id="manual-reason" maxlength="200" disabled></label></div><p class="muted"><?php echo $t( 'Discounts and tax are calculated by WooCommerce when you review the sale.' ); ?></p></div>
			<details class="parking-controls"><summary><?php echo $t( 'Save this basket for later' ); ?></summary><label><?php echo $t( 'Basket label (optional)' ); ?><input id="park-label" maxlength="100" autocomplete="off"></label><p class="muted"><?php echo $t( 'Saved online for seven days. Stock is not reserved until checkout.' ); ?></p><button id="park-cart" type="button" class="secondary full" disabled><?php echo $t( 'Save basket and clear counter' ); ?></button></details>
			<button id="prepare-sale" class="primary full" type="submit" disabled><?php echo $t( 'Review sale and reserve stock' ); ?></button>
		</form>
		<section id="payment-section" class="payment-section" hidden>
			<div class="payment-heading"><h3><?php echo $t( 'Record received funds' ); ?></h3><span id="countdown" role="timer"></span></div>
			<p class="muted"><?php echo $t( 'Only record money already received. This does not charge a card or contact a bank.' ); ?></p>
			<form id="payment-form"><div id="payment-lines"></div><button id="add-payment" type="button" class="text-button"><?php echo $t( 'Add payment method' ); ?></button><p id="payment-balance" role="status" aria-live="polite"></p><label class="confirmation"><input id="received" type="checkbox" required><span><?php echo $t( 'I confirm that the full amount has actually been received.' ); ?></span></label><button id="pay-sale" type="submit" class="primary full"><?php echo $t( 'Confirm payment and complete sale' ); ?></button></form>
			<button id="cancel-sale" class="text-button danger full" type="button"><?php echo $t( 'Cancel unpaid sale' ); ?></button>
		</section>
		<section id="recovery-section" class="recovery-section" hidden><h3><?php echo $t( 'Check the result before continuing' ); ?></h3><p id="recovery-hint" class="muted"><?php echo $t( 'Do not collect funds again. Check the recorded sale or retry the same request.' ); ?></p><button id="check-result" type="button" class="secondary full"><?php echo $t( 'Check sale status' ); ?></button><button id="retry-request" type="button" class="primary full"><?php echo $t( 'Retry the same request' ); ?></button><button id="abandon-attempt" type="button" class="text-button full"><?php echo $t( 'Abandon unresolved preparation' ); ?></button></section>
		<section id="complete-section" class="complete-section" hidden><span class="success-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 4 4L19 6"/></svg></span><h3 id="complete-title"></h3><p id="complete-detail"></p><div id="print-actions" class="print-actions"><label><?php echo $t( 'Receipt width' ); ?><select id="paper"><option value="80">80 mm</option><option value="58">58 mm</option></select></label><a id="print-receipt" class="secondary" target="_blank" rel="noopener"><?php echo $t( 'Open receipt / print' ); ?></a></div><button id="new-sale" type="button" class="primary full"><?php echo $t( 'Start next sale' ); ?></button></section>
		<details id="parked-carts" class="recent-sales"><summary><?php echo $t( 'My saved baskets' ); ?></summary><button id="refresh-parked" class="text-button" type="button"><?php echo $t( 'Refresh list' ); ?></button><div id="parked-list"></div></details>
		<details id="recent-sales" class="recent-sales"><summary><?php echo $t( 'My recent sales' ); ?></summary><p class="muted"><?php echo $t( 'Restore an unpaid sale or reprint a receipt. Only your sales are listed.' ); ?></p><button id="refresh-recent" class="text-button" type="button"><?php echo $t( 'Refresh list' ); ?></button><div id="recent-list"></div></details>
	</aside>
</div>
<div id="announcement" class="sr-only" role="status" aria-live="polite"></div>
<noscript><p class="notice error"><?php echo $t( 'JavaScript is required to use the online point of sale.' ); ?></p></noscript>
</body>
</html>
