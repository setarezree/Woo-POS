=== Universal WooCommerce POS & Store Manager ===
Contributors: setarezree
Tags: woocommerce, pos, inventory
Requires at least: 6.7
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modular WooCommerce POS with online sales and manually confirmed payments.

== Description ==
Author: ستاره زارعی

Lifecycle, versioned database schema, cashier capabilities, authenticated sales
and payment REST endpoints, stock reservations and HPOS-compatible order CRUD.
WooCommerce remains the only source of products, prices, orders and stock.
Responsive Persian/English cashier UI and protected thermal receipts are included.
No offline operation, shifts or terminal/gateway integration is included.
Manual payments require explicit confirmation of funds already received.

== Installation ==
1. Install and activate WooCommerce 9.0 or later.
2. Upload the plugin ZIP using Plugins > Add New > Upload Plugin.
3. Activate separately on each site; network activation is not supported.
4. See README.md for API access and the precise scope of this release.

== Frequently Asked Questions ==
= Does uninstall delete financial data? =
No. Plugin data, capabilities and cashier roles are intentionally retained.

= Can a cashier sell yet? =
Yes. Open Point of sale from the WordPress menu. Search/scan a SKU or barcode, build the
basket, review the server total and explicitly confirm funds received.
Thermal receipt layouts support 58mm and 80mm rolls. See docs/cashier-guide.md.

== Changelog ==
= 0.4.0 =
Native WooCommerce coupons, permissioned manual discounts with required reasons,
unique product/variation barcodes and owner-only server-saved seven-day baskets.
Baskets reprice on restore and convert once, without holding stock while parked.

= 0.3.0 =
Standalone cashier UI, native cookie/nonce authentication, SKU scanning,
variable-product selection, split-payment interface, uncertain-result recovery,
cashier-only recent sales and protected 58/80mm receipt printing.

= 0.2.0 =
Two-step POS sales, native stock holds, manually confirmed split payments,
idempotency, financial/stock/audit records and automatic unpaid-sale expiry.

= 0.1.0 =
Initial core, database and WooCommerce read integration.
