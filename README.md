# WCPOS StoreApps Smart Coupons

StoreApps Smart Coupons compatibility extension for WCPOS.

## Scope

This extension targets **Smart Coupons for WooCommerce by StoreApps / WooCommerce.com** only. It intentionally uses the `wcpos-storeapps-smart-coupons` slug because other plugins also use “Smart Coupons” naming.

Current compatibility layer:

- exposes StoreApps store-credit metadata on WCPOS coupon REST responses;
- captures `smart_coupons_contribution` order meta for POS REST orders so StoreApps can deduct the correct partial store-credit amount from gift-card balances;
- preserves normal WooCommerce coupons unchanged.

## Development

```bash
composer install
pnpm install
pnpm exec wp-env start
pnpm test
```

PHP/WordPress tests should run through Docker/wp-env.
