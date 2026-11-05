<?php
use StarZarei\UniversalPOS\Products\Barcode;
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap"><h1><?php esc_html_e( 'Barcode settings', 'universal-woocommerce-pos' ); ?></h1><?php settings_errors(); ?><form action="options.php" method="post"><?php settings_fields( 'upos_barcode' ); ?><table class="form-table" role="presentation"><tr><th scope="row"><label for="upos-barcode-meta-key"><?php esc_html_e( 'Barcode metadata key', 'universal-woocommerce-pos' ); ?></label></th><td><input class="regular-text" type="text" name="upos_barcode_meta_key" id="upos-barcode-meta-key" value="<?php echo esc_attr( Barcode::meta_key() ); ?>" dir="ltr" required maxlength="64"><p class="description"><?php esc_html_e( 'Existing data at the previous key is retained. Changing this setting does not migrate product metadata.', 'universal-woocommerce-pos' ); ?></p></td></tr></table><?php submit_button(); ?></form></div>
