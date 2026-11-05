<?php
use StarZarei\UniversalPOS\POS\Strings;
defined( 'ABSPATH' ) || exit;
$t = static fn( $text ) => esc_html( Strings::t( $text ) );
?>
<div id="upos-management" class="wrap upos-management" dir="<?php echo 'fa_IR' === Strings::locale() ? 'rtl' : 'ltr'; ?>">
 <h1><?php echo $t( 'inventory' === $view ? 'Inventory' : 'Orders and returns' ); ?></h1>
 <p><?php echo $t( 'inventory' === $view ? 'Stock is shared with WooCommerce. Record the real reason for each adjustment.' : 'Online and POS orders share the same WooCommerce records.' ); ?></p>
 <div id="management-notice" role="status" aria-live="polite" tabindex="-1" hidden></div>
 <form id="management-filters" class="upos-filters">
  <label><?php echo $t( 'Search' ); ?><input id="management-search" name="search" type="search" placeholder="<?php echo esc_attr( Strings::t( 'inventory' === $view ? 'Product name' : 'Order number, customer or phone' ) ); ?>"></label>
  <?php if ( 'inventory' === $view ) : ?>
  <label><?php echo $t( 'SKU' ); ?><input name="sku" type="text"></label>
  <label><?php echo $t( 'Stock status' ); ?><select name="stock_status"><option value=""><?php echo $t( 'All' ); ?></option value="instock"><?php echo $t( 'In stock' ); ?></option><option value="outofstock"><?php echo $t( 'Out of stock' ); ?></option></select></label>
  <?php else : ?>
  <label><?php echo $t( 'Channel' ); ?><select name="channel"><option value=""><?php echo $t( 'All' ); ?></option><option value="pos"><?php echo $t( 'POS' ); ?></option><option value="online"><?php echo $t( 'Online' ); ?></option></select></label>
  <label><?php echo $t( 'Status' ); ?><select name="status"><option value=""><?php echo $t( 'All' ); ?></option><?php foreach ( wc_get_order_statuses() as $status => $label ) : ?><option value="<?php echo esc_attr( substr( $status, 3 ) ); ?>"><?php echo esc_html( Strings::t( $label ) ); ?></option><?php endforeach; ?></select></label>
  <label><?php echo $t( 'From date' ); ?><input name="from" type="date"></label><label><?php echo $t( 'To date' ); ?><input name="to" type="date"></label>
  <?php endif; ?>
  <button class="button button-primary" type="submit"><?php echo $t( 'Apply filters' ); ?></button>
 </form>
 <div class="upos-table-scroll" tabindex="0" aria-label="<?php echo esc_attr( Strings::t( 'Results' ) ); ?>"><table class="widefat striped"><thead id="management-head"></thead><tbody id="management-rows"></tbody></table></div>
 <nav class="upos-pagination" aria-label="<?php echo esc_attr( Strings::t( 'Results pages' ) ); ?>"><button id="management-prev" class="button" type="button"><?php echo $t( 'Previous' ); ?></button><span id="management-page"></span><button id="management-next" class="button" type="button"><?php echo $t( 'Next' ); ?></button></nav>
 <section id="management-detail" class="upos-detail" aria-labelledby="detail-title" hidden><h2 id="detail-title" tabindex="-1"></h2><div id="management-content"></div></section>
 <script type="application/json" id="upos-management-config"><?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
</div>
