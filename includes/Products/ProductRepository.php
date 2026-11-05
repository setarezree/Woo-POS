<?php
namespace StarZarei\UniversalPOS\Products;

defined( 'ABSPATH' ) || exit;

final class ProductRepository {
	public function find( int $id ): ?\WC_Product {
		$product = wc_get_product( $id );
		return $product instanceof \WC_Product ? $product : null;
	}

	public function search( array $args ): object {
		return wc_get_products( array_merge( array( 'status' => 'publish', 'paginate' => true ), $args ) );
	}

	public function serialize( \WC_Product $product ): array {
		$attributes = array();
		foreach ( $product->get_attributes() as $name => $attribute ) {
			$attributes[] = $attribute instanceof \WC_Product_Attribute ? array(
				'name' => $attribute->get_name(),
				'options' => $attribute->get_options(),
				'variation' => $attribute->get_variation(),
			) : array( 'name' => $name, 'label' => wc_attribute_label( $name ), 'option' => $attribute );
		}
		return array(
			'id' => $product->get_id(),
			'parent_id' => $product->get_parent_id(),
			'type' => $product->get_type(),
			'name' => $product->get_name(),
			'status' => $product->get_status(),
			'purchasable' => $product->is_purchasable(),
			'sold_individually' => $product->is_sold_individually(),
			'sku' => $product->get_sku(),
			'price' => $product->get_price(),
			'regular_price' => $product->get_regular_price(),
			'sale_price' => $product->get_sale_price(),
			'manage_stock' => $product->get_manage_stock(),
			'stock_quantity' => $product->get_stock_quantity(),
			'stock_status' => $product->get_stock_status(),
			'stock_managed_by_id' => $product->get_stock_managed_by_id(),
			'backorders' => $product->get_backorders(),
			'category_ids' => $product->get_category_ids(),
			'attributes' => $attributes,
			'variation_ids' => $product->is_type( 'variable' ) ? $product->get_children() : array(),
			'image_id' => $product->get_image_id(),
			'image_url' => wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) ?: null,
			'gallery_image_ids' => $product->get_gallery_image_ids(),
		);
	}
}
