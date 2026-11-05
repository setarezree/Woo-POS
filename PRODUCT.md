# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Store cashiers register in-person purchases; authorized managers administer their WooCommerce store. The cashier's primary task is selecting products, recording the customer and confirming funds actually received.

## Product Purpose

Universal WooCommerce POS & Store Manager is a theme-independent WordPress plugin by ستاره زارعی. WooCommerce owns products, prices, variations, stock, coupons and orders. The plugin is an operational layer, with separate tables only for plugin-specific records.

## Operating Context

Online operation only. Native WordPress authentication, capabilities and REST nonces. No offline queue, service worker or cashier shifts. Persian and English, RTL, responsive layout and thermal receipts at 58/80 mm are required by the original brief. Preferred input device is not yet confirmed; this implementation assumes a keyboard-friendly desktop with touch-sized controls and a responsive mobile layout.

## Capabilities and Constraints

Version 0.4.0 already provides real pending orders, native WooCommerce stock reservations, server-calculated totals, explicitly confirmed manual split payments, cancellation/expiry, idempotency and financial/audit records. The cashier interface and receipts use that service. Version 0.4.0 extends the existing POS with coupons, permissioned discounts, barcodes and saved baskets.

The user's required development order is: core/database/WooCommerce; POS and real sales; inventory/orders/refunds; customers/cash/finance; dashboards/reports/final validation. The current work completes the planned supplementary POS features in the second stage; later modules must not be brought forward.

## Brand Commitments

No store-specific branding, address, currency, phone number or credentials in plugin code. Seller information and prices come from the actual WooCommerce site. The UI should be professional, minimal and usable; the established cashier visual system is documented in DESIGN.md. No store brand assets have been supplied.

## Evidence on Hand

README.md, docs/pos-api.md, existing PHP services and the user's original project specification. Product photographs, names and availability must come from the live catalog. No production sample catalog or fabricated business claims.

## Product Principles

- The server is authoritative for money, permissions and stock.
- Make prepare, payment confirmation and uncertain results visibly distinct.
- Preserve idempotency across retries; never silently create another sale.
- Expose only actions that actually work in this release.

## Accessibility & Inclusion

Keyboard and touch operation, visible focus, explicit form labels, announced errors, RTL/LTR support, responsive composition and reduced-motion support.

Current POS capabilities include native WooCommerce coupons, capability-gated amount/percent discounts with required reasons, dedicated product/variation barcodes, and owner-only seven-day server-saved baskets. Parked baskets hold IDs, quantities and customer/discount intent, never product prices or stock reservations; restoring resolves live WooCommerce data. Stage 3 remains inventory, general orders and refunds; customer CRM belongs to stage 4.
