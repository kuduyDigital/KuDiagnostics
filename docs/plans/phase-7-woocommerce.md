# Phase 7a — WooCommerce (plan)

Research: `RESEARCH.md` §10 (WooCommerce 11.2.2 source, read 2026-11-08; 11.0.1 for WordPress 6.9). Status: **plan approved 2026-11-08; D10–D13 decided (user): option A for each** — CRUD only, no money-moving actions, PII masked by default, existing scopes. No `kuduy-woo-order-refund`. Integrations (ACF, forms, blocks, Site Editor, widgets, cache, WPML/Polylang) and developer mode (D4) get their own plans later in Phase 7, each after its own research.

Exit gate (`ROADMAP.md`, WooCommerce part): every tool passes with HPOS **on** and **off**; secrets (gateway credentials, API keys, webhook secrets, payment tokens, order keys) are never returned or logged; customer personal data only reaches users allowed to see it.

## Decision D10 — how KUDUY reads and writes WooCommerce data

WooCommerce 11.2 registers seven abilities of its own (`woocommerce/products-query`, `product-create`, `-update`, `-delete`, `orders-query`, `order-update-status`, `order-add-note`). Its REST-bridge abilities exist only on its own experimental MCP endpoint, never in a KUDUY request.

| Option | What it means | For | Against |
|---|---|---|---|
| **A. Own tools on the WooCommerce CRUD API (recommended)** | `wc_get_product()` / `WC_Product*` setters + `save()`, `wc_get_orders()`, `WC_Order`, `WC_Coupon`, `WC_Customer`, `WC_Shipping_Zones`, `WC_Tax`; reports through WooCommerce's `wc-analytics` routes via internal `rest_do_request()`. Permission checks call WooCommerce's own `wc_rest_check_*_permissions()` functions | Public CRUD is WooCommerce's documented API and hides HPOS vs posts; one code path for every object; the `woocommerce_rest_check_permissions` filter that sites use still applies | More code than calling abilities |
| B. WooCommerce's abilities where they exist, CRUD for the rest | Products and order status/notes through the seven abilities | WooCommerce owns validation for those calls | Covers 7 of ~29 tools; fixed output shapes (e.g. `billing_email` always in order output); `product-delete` can force-delete; two code paths for the same objects |

Whichever option: never `update_post_meta` on WooCommerce objects, never raw SQL, never `wp_update_post` on products, orders or coupons.

## Decision D11 — actions that move money or reach customers

| Option | What it means |
|---|---|
| **A. Recommended** | **Not offered:** refunds of any kind, order creation, line-item/total edits, payment method or capture changes, permanent deletes (keeps `ARCHITECTURE.md` §8 rule 6). **Confirm-first:** order status changes and customer notes that make WooCommerce send an email to the customer — the first call returns the emails that would go out and needs `confirm: true` to run. Undo of a status change restores the status but cannot unsend an email; the result says so |
| B. As A, plus record-only refunds | `kuduy-woo-order-refund` with `wc_create_refund( refund_payment: false )` (no gateway call, money is not moved), `kuduy:admin` + `edit_post` on the order + `confirm: true`; restock optional. No undo (WooCommerce has no refund delete in CRUD that restores stock) |

## Decision D12 — customer personal data

| Option | What it means |
|---|---|
| **A. Masked by default (recommended)** | Lists return customer name and an email masked to `j***@example.com`; full billing/shipping address, phone, email and the order's IP/user agent only with `include_pii: true` **and** the per-object capability (`edit_post` on the order, `edit_users` per WooCommerce's user rule for customers). Audit rows never store PII (arguments and results pass a PII masker before the Redactor). |
| B. Full data to anyone with the capability | What WooCommerce's admin and REST do |

Never returned, in any option: gateway secrets, REST API keys, webhook secrets, payment tokens, order keys, cart hashes, password-reset data.

## Decision D13 — scopes

| Option | What it means |
|---|---|
| **A. Existing scopes (recommended)** | Reads `kuduy:read`; catalog, coupon, review, customer-address and order-status writes `kuduy:write`; store configuration reads (payment gateways) `kuduy:admin`. Capabilities do the fine-grained part (a shop manager has the WooCommerce caps, an editor does not) |
| B. New scope `kuduy:shop` | Separate consent box for store data. Needs a consent change, a new offered-scope rule, and claude.ai (which requests no scopes) would need it offered unticked like `kuduy:admin` |

## Tools (module `src/Modules/Woo/`, available only when `WC_VERSION` is defined)

Capability column = the check run per call; "per object" means `current_user_can( meta cap, $id )` on that object, as WooCommerce's REST does.

| # | Tool | Scope | Capability | Snapshot / undo |
|---|---|---|---|---|
| 1 | `kuduy-woo-status` — version, HPOS on/off + sync, currency, prices incl. tax, tax/shipping/coupons/reviews/analytics enabled, coming-soon mode, store pages | read | `edit_products` or `read_private_shop_orders` | — |
| 2 | `kuduy-woo-product-list` — type, status, category, tag, SKU, search, stock status, on sale; paged | read | `read_private_products` | — |
| 3 | `kuduy-woo-product-get` — props, prices, stock, attributes, images, categories, variations summary, downloads (file names; URLs only with `edit_post`) | read | `read_private_products` | — |
| 4 | `kuduy-woo-variation-list` — variations of a variable product with attributes, price, stock | read | `read_private_products` | — |
| 5 | `kuduy-woo-product-create` — simple, variable, external, grouped; draft by default | write | `publish_products` (create), `publish_products` again to publish | trash on undo |
| 6 | `kuduy-woo-product-update` — patch: name, slug, status, descriptions, SKU, prices, sale dates, tax status/class, shipping class, weight/dimensions, categories, tags, images, attributes, visibility, featured, stock management, upsells/cross-sells | write | `edit_post` per product; `publish_products` to publish | `get_data()` before, restored through setters + `save()` |
| 7 | `kuduy-woo-variation-create` — from attribute values; parent `sync()` after | write | `edit_post` on parent | trashed on undo (`delete( false )` trashes variations too) |
| 8 | `kuduy-woo-variation-update` — price, sale, SKU, stock, status, image, attributes, dimensions | write | `edit_post` on parent | props restored; parent `sync()` |
| 9 | `kuduy-woo-product-stock` — `set` / `increase` / `decrease` for a product or variation through `wc_update_product_stock()` | write | `edit_post` per product | previous quantity and status |
| 10 | `kuduy-woo-product-trash` — trash (never force) | write | `delete_post` per product | `wp_untrash_post` + variations |
| 11 | `kuduy-woo-order-list` — status, dates, customer, product, search, totals; paged; PII masked (D12) | read | `read_private_shop_orders` | — |
| 12 | `kuduy-woo-order-get` — items, totals, taxes, shipping, coupons, notes, refunds (read only), status history; `include_pii` (D12) | read | `read_private_shop_orders`; PII needs `edit_post` on the order | — |
| 13 | `kuduy-woo-order-status` — change status; confirm-first when customer emails would be sent (D11) | write | `edit_post` per order | previous status (email not unsent) |
| 14 | `kuduy-woo-order-note` — private note; customer note is confirm-first (email) | write | `edit_post` per order | private note deleted on undo |
| 15 | `kuduy-woo-coupon-list` — code, type, amount, usage, expiry; paged | read | `read_private_shop_coupons` | — |
| 16 | `kuduy-woo-coupon-get` — by id or code (restrictions, limits, products, emails allowed) | read | `read_private_shop_coupons` | — |
| 17 | `kuduy-woo-coupon-create` — code unique (`wc_get_coupon_id_by_code`) | write | `publish_shop_coupons` | trash on undo |
| 18 | `kuduy-woo-coupon-update` — patch | write | `edit_post` per coupon | `get_data()` restored |
| 19 | `kuduy-woo-coupon-trash` | write | `delete_post` per coupon | untrash |
| 20 | `kuduy-woo-customer-list` — customers with order count, total spent, last order; masked (D12) | read | `list_users` | — |
| 21 | `kuduy-woo-customer-get` — `include_pii` (D12) | read | `list_users`; PII needs WooCommerce's edit rule for that user | — |
| 22 | `kuduy-woo-customer-update` — billing/shipping address fields only (no email, role or password) | write | `wc_rest_check_user_permissions( 'edit', $id )` (shop manager: customers only) | previous fields |
| 23 | `kuduy-woo-review-list` — product, rating, verified, status; paged | read | `moderate_comments` | — |
| 24 | `kuduy-woo-review-moderate` — approve, hold, spam, trash; rating cache refreshed (`WC_Comments::clear_transients`) | write | `wc_rest_check_product_reviews_permissions( 'edit', $id )` | previous status |
| 25 | `kuduy-woo-review-reply` — reply as the current user | write | `edit_products` + `moderate_comments` | reply trashed on undo |
| 26 | `kuduy-woo-shipping` — zones, regions, methods with their settings (password-type fields masked) | read | `manage_woocommerce` | — |
| 27 | `kuduy-woo-tax` — tax enabled, display settings, classes, rates per class | read | `manage_woocommerce` | — |
| 28 | `kuduy-woo-payment-gateways` — id, title, enabled, test/sandbox mode, supported features; settings with secrets masked | admin | `manage_woocommerce` | — |
| 29 | `kuduy-woo-report` — `report`: revenue, orders, products, variations, categories, coupons, taxes, stock, customers; period, interval, compare; from `wc-analytics` | read | `view_woocommerce_reports` | — |

Jobs (D9): `kuduy-job-start` gains `kuduy-woo-product-update`, `kuduy-woo-variation-update`, `kuduy-woo-product-stock`, `kuduy-woo-coupon-update` (bulk price, stock and sale changes; each item checked and snapshotted like a single call).

Not offered (D11 A): refunds, order creation or item edits, payment changes, gateway/shipping/tax **writes** (later, after read tools are tested on staging), webhook and API-key management, permanent deletes. With D11 B, a 30th tool `kuduy-woo-order-refund`.

## Guards in existing tools

- `kuduy-post-create`, `-update`, `-trash` and `kuduy-post-meta-set` refuse WooCommerce types (`product`, `product_variation`, `shop_order`, `shop_order_refund`, `shop_order_placehold`, `shop_coupon`) while WooCommerce is active and name the `kuduy-woo-*` tool to use. Reason: `wp_update_post` / meta writes skip WooCommerce's lookup tables, caches and hooks. Reads stay allowed.
- `kuduy-comment-moderate` / `-reply` on reviews keep working but do not refresh the rating cache; their result points to `kuduy-woo-review-*`.

## Redaction

- `Redactor` key pattern gains `signature`, `consumer_key`, `order_key`, `cart_hash`, `payment_token` (test first: present gaps in `RESEARCH.md` §10).
- Gateway, shipping-method and integration settings are masked by **field type**: any form field of type `password` becomes `[redacted]` (and `has_value: true|false`), whatever its name, because extensions name secrets freely.
- `Woo\Pii` masks email, phone, address lines, IP and user agent unless the call allowed PII (D12); audit rows always get the masked form.
- Tests feed known secrets (PayPal `api_password`, `api_signature`, `identity_token`; a fake extension gateway with a `password` field named `merchant_pin`; a webhook secret; an API key row; an order key) and assert none appears in any tool result, error or audit row.

## HPOS on/off

- KUDUY reads storage only through CRUD and `OrderUtil`; no table names in its code. `kuduy-woo-status` reports `OrderUtil::custom_orders_table_usage_is_enabled()` and `custom_orders_table_data_sync_is_enabled()`.
- When KUDUY adds the `WC tested up to` header, it declares `FeaturesUtil::declare_compatibility( 'custom_order_tables', KUDUY_MCP_FILE, true )` in `before_woocommerce_init` in the same change (without the header WooCommerce ignores KUDUY; with it and no declaration, HPOS would treat KUDUY as incompatible).
- Every order test (list, get, status, note, trash state, PII, per-object capability, undo) runs twice: HPOS on (sync off) and HPOS off. A per-object capability test with HPOS on and sync off proves WooCommerce's `HposOrderCapabilityHelper` mapping is what KUDUY relies on.

## Playground setup (`scripts/test-integration.mjs`)

- `KUDUY_WOO=<version>` installs WooCommerce from wordpress.org (11.2.2 on 7.1, 11.0.1 on 6.9; `none` = off, the default for runs that do not need it); a `setSiteOptions` step after it sets `woocommerce_coming_soon: no` and `KUDUY_HPOS=on|off` → `woocommerce_custom_orders_table_enabled: yes|no` (fresh shop, no orders, so WooCommerce accepts the switch: **UNVERIFIED**, first test).
- `WooTestCase::setUpBeforeClass()` asserts `OrderUtil::custom_orders_table_usage_is_enabled()` equals `KUDUY_HPOS` (passed into Playground through `env.txt` like the versions) and fails loudly otherwise, so a silent fallback cannot hide one mode.
- Fixtures through CRUD in the tests: simple and variable products, a coupon, a customer user, orders in several statuses, a review with rating. Users: administrator, `shop_manager`, editor (no WooCommerce caps), author, customer.
- Emails: `pre_wp_mail` captures mail so confirm-first status tests can assert which emails were sent.
- Reports: analytics import is scheduled; tests run the import for their orders directly (WooCommerce's own importer, **UNVERIFIED** call — read in tests first) before asserting report numbers.
- CI: four new jobs — WP 6.9/PHP 8.1 + WooCommerce 11.0.1 and WP 7.1/PHP 8.3 + 11.2.2, each with `KUDUY_HPOS=on` and `off`. Tests skip when WooCommerce is not active.

## Progress

- 2026-11-09: step 1 done — core post tools refuse WooCommerce types (W1), `Redactor` masks WooCommerce secret keys and `password`-type settings fields (W2 Redactor part), runner `KUDUY_HPOS`, four CI jobs. Found in tests: with WooCommerce active a non-administrator can never edit an administrator (`wc_modify_map_meta_cap`), so `CoreUsersTest`'s last-admin case is refused by the capability check first; WordPress started a background update of the older WooCommerce 11.0.1 on 6.9 and left `.maintenance`, so the runner now sets `AUTOMATIC_UPDATER_DISABLED`. HPOS switching through `setSiteOptions` on a fresh shop works (verified by `WooGuardsTest`).

- 2026-11-09: step 2 done — `kuduy-woo-status`, `kuduy-woo-product-list` / `-get` / `-create` / `-update` / `-stock` / `-trash`, `kuduy-woo-variation-list` / `-create` / `-update` (W3–W5, `WooProductsTest`). Plan change: creates (5, 7) keep no snapshot, like `kuduy-post-create` — a product is created as a draft and removed with `kuduy-woo-product-trash` (undoable); a variation can be disabled with `status: private`. Found in tests: `WC_Data::delete()` resets the object id to 0; the parent's variation price range is formatted with the store decimals ("30.00").

- 2026-11-09: step 3 done — `kuduy-woo-order-list` / `-get` / `-status` / `-note` (W6–W8, `WooOrdersTest`, HPOS on and off). Plan changes: order notes have no undo (the snapshot is taken before the note exists; notes only add text); `refunded` is refused by `kuduy-woo-order-status` because WooCommerce creates a refund record for it (`wc_order_fully_refunded`); without `include_pii` addresses keep names, company and country.

- 2026-11-09: step 4 done — coupons (`kuduy-woo-coupon-list` / `-get` / `-create` / `-update` / `-trash`, W9), customers (`kuduy-woo-customer-list` / `-get` / `-update`, W10), reviews (`kuduy-woo-review-list` / `-moderate` / `-reply`, W11). New `MasksAuditArgs` interface: customer address arguments never reach the audit log or the snapshot input. Plan changes: coupon creates and review replies keep no snapshot (like other creates; trash a coupon with `kuduy-woo-coupon-trash`, a reply with `kuduy-comment-moderate`); coupons are active (publish) when created, as in WooCommerce's editor. Found in source: WooCommerce's code lookup only sees published coupons, so a draft's code is not reserved; an empty minimum/maximum spend reads as "0" in view context.

- 2026-11-09: step 5 done — `kuduy-woo-shipping`, `kuduy-woo-tax`, `kuduy-woo-payment-gateways` (read only), `kuduy-woo-report` (W12–W14, W2 end-to-end). All 29 planned WooCommerce tools exist (no refund tool, D11). Settings fields are masked by type `password` and by credential-like names. Found in tests: report rows only carry product names with `extended_info`, which also adds customer details to order rows (masked). Left for the WooCommerce exit gate: job allow-list (step 6) and the staging run with claude.ai.

- 2026-11-09: step 6 code done — `kuduy-job-start` accepts `kuduy-woo-product-update`, `-variation-update`, `-product-stock`, `-coupon-update` (`WooJobsTest`); order status, notes and trash stay single calls. Left: the staging run with claude.ai (needs a release).

- 2026-11-09: released as 0.6.0; staging run with claude.ai: 11 of 14 steps pass, 3 not testable on that store (orders, customers, gateway secrets — covered in Playground). Fixed afterwards: report intervals were cut at 10. Open: re-run the order, customer and gateway steps on staging once it has a test order and a sandbox gateway; then the WooCommerce part of the exit gate is fully evidenced on a real host.

## Order

1. Guards in core post tools + `Redactor` additions (W1, W2).
2. `kuduy-woo-status`, products read (2–4), product writes (5–10) (W3–W5).
3. Orders 11–14 with HPOS on/off and emails (W6–W8).
4. Coupons, customers, reviews (W9–W11).
5. Shipping, tax, gateways (masking) and reports (W12–W14).
6. Job allow-list; staging run with claude.ai web (HPOS state of staging read first; a test product and order on staging, cleaned up afterwards).

## Tests (feature matrix ids for `TESTING.md`)

- W1 core post tools refuse WooCommerce types; W2 secrets never in results/errors/audit; W3 product CRUD round trip through `wc_get_product()` and lookup table (`wc_product_meta_lookup`) updated; W4 variations + parent sync; W5 stock ops + undo; W6 order read/PII masking both HPOS modes; W7 order status confirm-first + emails captured + undo; W8 per-object order caps (shop manager yes, editor no, author no) both modes; W9 coupons unique code + undo; W10 customer address update, shop manager limited to customers; W11 reviews moderation + rating cache; W12 shipping/tax read; W13 gateway masking (type `password`); W14 report numbers match fixture orders.
- Permission boundary for every write: editor and author refused, shop manager allowed, other users' objects follow WooCommerce's rule.
