# Changelog

## 0.2.1 - 2026-08-26

- Deduct gift-card balances when a POS order is paid. StoreApps' balance deduction only runs on `pending`/`failed` → paid transitions, and its third-party status handler ignores transitions into core statuses — so WCPOS orders moving from `pos-open`/`pos-partial` to `processing`/`completed` never reduced the store-credit balance. The extension now invokes StoreApps' own (idempotent) `update_smart_coupon_balance` for those transitions.

## 0.2.0 - 2026-08-26

- Apply StoreApps store-credit (gift card) coupons to order totals at the WCPOS checkout. StoreApps only applies `smart_coupon` credit for admin order edits, WooCommerce REST requests, and `store-api` orders; the POS checkout coupon form is a plain front-end POST, so gift cards validated but never changed the amount due. While an unpaid POS order carrying a store-credit coupon recalculates totals, the extension now reports a REST context so StoreApps' own order-discount logic runs. Verified against WCPOS Pro 1.9.9 and 1.10.1 with StoreApps Smart Coupons 9.77.0, in both store-credit tax modes.
- Prune stale `smart_coupons_contribution` entries when a store-credit coupon is removed at the POS checkout, so removed gift cards are no longer deducted when the order is paid.
- Ignore sub-cent float residue when inferring store-credit usage from POS order totals; tax-inclusive orders could otherwise record phantom contributions of fractions of a cent.

## 0.1.1 - 2026-06-02

- Fix POS store-credit balance deductions when WCPOS sends signed or zero-value coupon line discounts to StoreApps Smart Coupons.
- Add guarded inference for POS orders where the store-credit usage is present on the order totals rather than the coupon line.
- Avoid over-deducting ambiguous mixed-coupon orders and POS orders with negative fee/manager discount lines.
- Expand regression coverage for StoreApps balance updates, mixed coupons, tax mode differences, zero-value coupon lines, and negative fees.

## 0.1.0 - 2026-05-20

- Initial StoreApps Smart Coupons compatibility layer for WCPOS.
