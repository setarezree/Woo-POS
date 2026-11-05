---
name: Universal WooCommerce POS & Store Manager
description: A restrained, bilingual cashier workspace for real WooCommerce sales.
colors:
  ink: "#172b35"
  muted: "#546773"
  canvas: "#f3f5f4"
  surface: "#fff"
  subtle: "#eaf0ed"
  line: "#d6dfda"
  accent: "#17695b"
  accent-hover: "#105345"
  error: "#a92e35"
  focus: "#146c94"
  selection: "#c6e7dc"
  error-surface: "#fff3f1"
  error-line: "#edc9c7"
typography:
  body:
    fontFamily: 'Tahoma, "Segoe UI", sans-serif'
    fontSize: "15px"
  title:
    fontFamily: 'Tahoma, "Segoe UI", sans-serif'
    fontSize: "1.24rem"
    fontWeight: 700
  store-title:
    fontFamily: 'Tahoma, "Segoe UI", sans-serif'
    fontSize: "1.15rem"
    fontWeight: 700
    lineHeight: 1.6
  section-title:
    fontFamily: 'Tahoma, "Segoe UI", sans-serif'
    fontSize: "1rem"
    fontWeight: 700
    lineHeight: 1.7
  label:
    fontFamily: 'Tahoma, "Segoe UI", sans-serif'
    fontSize: ".87rem"
    fontWeight: 600
    lineHeight: 1.5
  product-name:
    fontFamily: 'Tahoma, "Segoe UI", sans-serif'
    fontSize: ".91rem"
    fontWeight: 600
    lineHeight: 1.9
  receipt-body:
    fontFamily: 'Tahoma, "Segoe UI", sans-serif'
    fontSize: "13px"
    lineHeight: 1.9
rounded:
  compact: "6px"
  control: "8px"
  surface: "12px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "12px"
  lg: "16px"
  xl: "24px"
  xxl: "32px"
components:
  button-primary:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.surface}"
    rounded: "{rounded.control}"
    padding: "11px 17px"
  button-primary-hover:
    backgroundColor: "{colors.accent-hover}"
  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    padding: "11px 17px"
  button-secondary-hover:
    backgroundColor: "{colors.subtle}"
  button-text:
    backgroundColor: "transparent"
    textColor: "{colors.accent}"
    padding: "8px"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    padding: "10px 12px"
  product-card:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.surface}"
    padding: "0"
  mobile-tab:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.muted}"
    width: "50%"
  mobile-tab-selected:
    textColor: "{colors.accent}"
  notice:
    backgroundColor: "{colors.subtle}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    padding: "14px 16px"
  notice-error:
    backgroundColor: "{colors.error-surface}"
    textColor: "{colors.error}"
  quantity:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.compact}"
---

# Design System: Universal WooCommerce POS & Store Manager

## Overview

The cashier interface follows the original minimal, professional brief: light, low-glare surfaces, dark ink and a restrained deep teal for primary actions and selected controls. It is an operational surface layered on the existing WooCommerce POS backend. Its density supports repeated product selection and verification of funds actually received.

Persian RTL and English LTR share the same visual system. Store identity, catalog photographs, names, stock and money come from the actual store. The current scope is the cashier and thermal receipt; dashboards and later management modules have no implemented design system here. No user-approved creative metaphor or store-specific identity has been established.

**Key Characteristics:**
- Light surfaces, quiet borders and a single primary action color.
- Compact, readable typography with tabular financial figures.
- A catalog beside an order workspace on desktop, with two workspace tabs on mobile.
- Visible preparation, payment, recovery and completion states.

## Colors

Deep teal supplies the primary accent; neutral surfaces and semantic error and focus colors support it. The frontmatter records the actual CSS colors, preserving the source representation. The sidecar’s eight-step tonal ramps are synthesized OKLCH panel previews, not additional runtime palette tokens.

### Primary
- **Deep teal — accent:** primary buttons, available stock, links, selected tabs and connection success.
- **Deep teal hover — accent-hover:** enabled primary-button hover.

### Neutral
- **Dark ink — ink:** main text and totals.
- **Slate — muted:** supporting copy, SKU, unavailable stock and inactive tabs.
- **Soft canvas — canvas:** application background and disabled fields.
- **White — surface:** header, product tiles, order workspace and controls.
- **Pale green — subtle:** secondary hover, notices and small action surfaces.
- **Quiet border — line:** dividers and control outlines.
- **Pale selection — selection:** selected text and brief basket-count feedback.

Semantic colors are **error** for failed or unbalanced states, **error-surface/error-line** for error notices, and **focus** for the keyboard outline. Connection and stock labels always include words alongside color. Receipt output is monochrome; its toolbar retains the application accent and focus treatment.

**The Action Color Rule.** Use the primary accent to identify actionable and selected states; communicate financial state in text as well as color.

## Typography

The shared stack is Tahoma, Segoe UI, sans-serif, with no downloaded font. The implemented type scale is compact, rather than the provisional larger page-title range from the initial brief. Frontmatter roles reflect the source; there is no display or hero role.

- **Body:** the root uses the body role; supporting paragraphs set their own line heights for readable Persian copy.
- **Title:** catalog and basket headings use the title role; the store name uses store-title, reduced to .98rem at the mobile breakpoint.
- **Section title:** form, payment and completion headings use section-title.
- **Label:** fields use the label role with an 8px label-to-field gap.
- **Product name:** product-name wraps freely; at mobile it reduces to .85rem. SKU and stock use .72rem.
- **Money:** prices, line values, totals, payment amounts and counts use tabular numerals. Currency formatting follows store settings and isolates bidirectional money strings.
- **Receipt:** receipt-body reduces to 11px for the 58mm variant. The store heading is 19px; the grand total is 15px, reduced to 12px on the narrow variant.

## Layout

The desktop shell is centered with a maximum width of 1800px. Its base grid is a flexible catalog plus a 410px order column; above 1500px the order column becomes 440px. The 88px header uses 16px/32px padding. Catalog padding is 28px/32px; order padding is 28px/24px. Logical borders and alignment follow document direction: catalog right and order left in RTL, reversed in LTR.

At 1100px and above the order pane sticks to the viewport top, scrolls independently, and has a maximum height of 100vh. At 1099px and below its width is 360px, catalog padding becomes 24px and order padding 24px/20px. Product columns auto-fill from 168px, increase to 185px at 1500px, and reduce to 150px at 1099px; gaps are 16px or 12px respectively.

At 760px and below the shell becomes a single workspace. Sticky, equal-width Products/Basket buttons switch the visible pane and expose the basket quantity. The header wraps, hides the operator and store mark, and keeps connection/sign-out controls. The catalog uses two equal columns, 20px/16px pane padding, 12px tile gaps and 136px image areas. The order uses 24px/20px padding. Notices from the order pane are mirrored into the visible catalog so errors and connection feedback remain visible there.

The spacing vocabulary in frontmatter is a recurring rhythm, not an exclusive scale: component-specific source values such as 10px, 14px, 18px, 20px and 28px also remain in use. Common fields have a 44px minimum height, primary/secondary actions 46px and search/mobile tabs 52px. Small embedded action affordances are part of their larger product button.

The receipt view provides 80mm and 58mm paper choices. Screen receipt widths are 76mm with 5mm padding and 54mm with 3mm padding. Print removes toolbar, margins and internal receipt padding, uses the corresponding body width and a 2mm page margin. At viewport widths up to 400px the toolbar compacts and the receipt stays within the screen. Physical roll size still depends on browser/printer settings.

## Elevation & Depth

The current system uses no box shadows, gradients or blur. White panels, pale fills, quiet one-pixel borders and separator lines establish depth. Sticky order and mobile navigation position functional surfaces without adding elevation. Receipt sections use dashed or solid monochrome rules.

**The Flat Surface Rule.** Preserve tonal separation and borders as the depth vocabulary of the current cashier surface.

## Shapes

Product tiles and the store mark use the surface radius. Fields, primary/secondary buttons and notices use the control radius. Quantity groups, product-add affordances and text-button hover use the compact radius. Connection dots and the completed-sale mark are circular. Product tiles clip their image area; catalog photographs use contain sizing and a pale backdrop. Order lines use separators rather than nested cards.

## Components

### Buttons

Primary actions use teal with white text; secondary actions use a white fill and border. Both have 600 weight and 1.5 line height. Text actions use transparent backgrounds and teal text; cancellation uses error-colored text. Primary hover darkens the fill, secondary/text hover uses the subtle fill, and enabled press applies brightness(.96). Disabled buttons reduce opacity to .48 and show a not-allowed cursor; unavailable product buttons use .7 opacity.

All interactive elements use a 3px focus-colored outline with 3px offset on keyboard focus. Background/text color transitions run for .16s ease. Reduced-motion mode disables transitions and animations.

### Inputs / Fields

Fields have white fill, a one-pixel quiet border and the control radius. Disabled fields use the canvas fill. Search reserves inline-start space for its SVG, and has a 52px minimum height. Explicit labels or screen-reader labels identify fields. Telephone/email fields use LTR entry within either page direction. The received-funds checkbox is a native, accent-colored 20px control beside a full text label.

### Product tiles

The full tile is the action, with an image or initials fallback, wrapping name, SKU, availability and price. Available tiles gain an accent border and near-white green fill on hover. Each action's accessible description includes both stock and price. Real catalog images have empty alternate text because the surrounding button names the product. Variations replace the catalog in place and expose an All products return control.

### Basket and quantity

Order lines use a two-column name/value arrangement and bottom separators. Names wrap while line amounts remain together. The quantity group has a bordered compact silhouette, 40px-wide decrease/increase controls and a 44px-wide numeric field. The final total uses greater size and weight. Provisional totals retain an explicit estimate explanation until the server prepares the sale.

### Navigation

There is no dashboard sidebar. Mobile workspace buttons use muted text and a transparent bottom border; selection switches to accent text and a 2px accent underline. Their pressed state is explicit. The basket count updates after addition and briefly highlights for .35s ease-out. The same quantity remains visible when animation is disabled.

### Notices and sale states

Neutral notices use the subtle surface and quiet border; errors use error text and the dedicated pale error surface/border. Messages wrap and are announced. Preparation, received-funds confirmation, unresolved requests and completion use distinct inline sections in the order workspace. Unresolved requests disable relevant mutations. The received-funds confirmation appears only after the server returns the prepared total; payment is a record of funds already received.

### Search and focus

Slash focuses search while editing. An exact SKU submission adds an available simple product and returns focus to search. Keyboard product activation also returns focus to search; pointer activation retains its interaction context. Successful additions are announced and update the basket count. Do not force a pointer user's viewport back to search.

### Receipt

A separate focused document presents actual store, customer, order and payment data, with a paper-width selector and print action. The receipt is monochrome with tabular amounts, wrapping names/references, repeated table headers and protected row/payment/footer breaks. The browser toolbar is absent from print output.

## Do's and Don'ts

### Do:
- **Do** preserve the minimal professional character, logical alignment and shared RTL/LTR system.
- **Do** use real store/catalog information and let names, notices and references wrap.
- **Do** keep stock, estimates, final totals and received-funds status explicit in text.
- **Do** retain visible keyboard focus, mobile-visible feedback and reduced-motion behavior.
- **Do** use separators and tonal fills to organize the cashier workspace.

### Don't:
- **Don't** introduce decorative metrics, charts or dashboard framing into this operational surface.
- **Don't** hide uncertain payment outcomes or imply that recording funds charges a card.
- **Don't** hardcode a store brand, currency or sample catalog into the application.
- **Don't** turn this cashier implementation into an invented design specification for later modules.
