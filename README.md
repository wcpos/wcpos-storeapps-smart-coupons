# WCPOS StoreApps Smart Coupons

StoreApps Smart Coupons compatibility extension for WCPOS.

## Scope

This extension targets **Smart Coupons for WooCommerce by StoreApps / WooCommerce.com** only. It intentionally uses the `wcpos-storeapps-smart-coupons` slug because other plugins also use “Smart Coupons” naming.

Current compatibility layer:

- applies store-credit (gift card) coupons to order totals at the WCPOS checkout by reporting a REST context while an unpaid POS order with a `smart_coupon` recalculates totals, so StoreApps' own order-discount logic runs (its gates otherwise only open for admin order edits, REST requests, and `store-api` orders);
- prunes stale `smart_coupons_contribution` entries when a store-credit coupon is removed at the POS checkout, so removed gift cards are not deducted on payment;
- captures `smart_coupons_contribution` order meta for POS REST orders so StoreApps can deduct the correct partial store-credit amount from gift-card balances;
- adds a private order note after StoreApps processes the POS store-credit redemption so staff have an audit trail of the coupon code, amount used, and current balance;
- appends the remaining store-credit balance to the existing coupon description/discount label while WCPOS receipts are rendered, so current receipt templates show it in the discount row without custom receipt data;
- preserves normal WooCommerce coupon REST responses and relies on StoreApps/WooCommerce coupon metadata instead of adding WCPOS-specific coupon fields.

## Development

```bash
composer install
pnpm install
pnpm exec wp-env start
pnpm test
```

PHP/WordPress tests should run through Docker/wp-env.
