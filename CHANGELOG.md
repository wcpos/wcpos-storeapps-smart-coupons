# Changelog

## 0.2.3 - 2026-09-02

- Mark store-credit rows in WCPOS receipt data. WCPOS 1.10.8 filters its canonical receipt payload through `woocommerce_pos_receipt_data`; for POS orders paid with StoreApps store credit the extension now adds `gift_card: true` to the matching discount row and puts the balance text on the row label. Templates can print "Gift Card" instead of "Discount" with `{{#gift_card}}Gift Card{{/gift_card}}{{^gift_card}}{{i18n.discount}}{{/gift_card}}`. Older WCPOS versions keep the coupon-description path from 0.2.2.

## 0.2.2 - 2026-09-02

- Restore the "Store credit balance" text on POS receipts under WCPOS 1.10. WCPOS 1.10 moved the POS app onto the `wcpos/v2` REST namespace, and the receipt-order context was only recognised on `/wcpos/v1/receipts/`, so the balance label silently disappeared from receipt discount lines. The extension now matches any `wcpos/v{n}` receipts route. Verified live against WCPOS Pro 1.10.2 (bundled free 1.10.6) with StoreApps Smart Coupons 9.77.0.
- Include the balance label in WCPOS 1.10 fiscal receipt snapshots. WCPOS captures an immutable receipt snapshot on `woocommerce_payment_complete` and serves it in fiscal receipt mode; the receipt-order context is now set for POS orders around that hook so the label is present at capture time.

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
